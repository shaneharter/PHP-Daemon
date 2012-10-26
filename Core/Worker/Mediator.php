 <?php
/**
 * Create and run worker processes.
 * Use message queues and shared memory to coordinate worker processes and return work product to the daemon.
 * Uses system v message queues because afaik there's no existing PHP implementation of posix  queues.
 *
 * At a high level, workers are implemented using a Mediator pattern. When a worker is created (by passing a Callable or
 * an instance of Core_IWorker to the Core_Daemon::worker() method) the Daemon creates a Mediator instance and
 * passes-in the worker.
 *
 * When worker methods are called the Daemon is actually interacting with the Mediator instance. Calls are serialized in
 * a very simple proprietary serialization format (to avoid any additional dependencies) and dispatched to worker processes.
 * The Mediator is responsible for keeping worker processes running, mediating calls and returns, and enforcing timeouts on jobs.
 *
 * The daemon does have the option of disintermediating work by calling methods directly on the worker object. If a worker
 * alias was Acme, a disintermediated call to doSomething() from the Daemon execute() method would look like:
 * @example $this->Acme->inline()->doSomething();   // Call doSomething() in-process (blocking)
 *
 * And if Acme was a Function worker it would work in a similar way:
 * @example $this->Acme->inline();
 *
 *
 * @todo Improve the dump() feature to include data-driven advice about the suggested memory allocation
 *       and number of workers. It's very hard for a novice to have any feeling for those things and they are vital to
 *       having a worker that runs flawlessly.
 *
 * @author Shane Harter
 */
abstract class Core_Worker_Mediator implements Core_ITask
{
    /**
     * The version is used in case formats change in the future.
     */
    const VERSION = 2.0;

    /**
     * Message Types
     */
    const WORKER_CALL = 3;
    const WORKER_RUNNING = 2;
    const WORKER_RETURN = 1;

    /**
     * Call Statuses
     */
    const UNCALLED = 0;
    const CALLED = 1;
    const RUNNING = 2;
    const RETURNED = 3;
    const CANCELLED = 4;
    const TIMEOUT = 10;

    /**
     * Forking Strategies
     */
    const LAZY = 1;
    const MIXED = 2;
    const AGGRESSIVE = 3;

    /**
     * Map call statuses to the logical queue that messages will be written-to.
     * @example When a call is in UNCALLED status, it should be written to the WORKER_CALL queue.
     * @var array
     */
    public static $queue_map = array(
        self::UNCALLED  => self::WORKER_CALL,
        self::RUNNING   => self::WORKER_RUNNING,
        self::RETURNED  => self::WORKER_RETURN
    );

    /**
     * The forking strategy of the Worker
     *
     * @example self::LAZY
     * Daemon Startup:      No processes are forked
     * Worker Method Call:  If existing process(es) are busy, fork another worker process for this call, up to the workers() limit.
     * In Lazy forking, processes are only forked as-needed
     *
     * @example self::MIXED
     * Daemon Startup:      No processes are forked
     * Worker Method Call:  Fork maximum number of worker processes (as set via workers())
     * In Mixed forking, nothing is forked until the first method call but all forks are done simultaneously.
     *
     * @example self::AGGRESSIVE
     * Daemon Startup:      All processes are forked up front
     * Worker Method Call:  Processes are forked as-needed to maintain the max number of available workers
     *
     * @var int
     * @todo improve the intelligence behind the strategy selection to vary strategy by idle time in the daemon event loop, not the duration of the loop itself.
     */
    protected $forking_strategy = self::MIXED;

    /**
     * @var Core_Daemon
     */
    public $daemon;

    /**
     * @var Core_IWorkerVia
     */
    protected $via;

    /**
     * Running worker processes
     * @var stdClass[]
     */
    protected $processes = array();

    /**
     * Methods available on the $object
     * @var array
     */
    protected $methods = array();

    /**
     * All Calls
     * A periodic garbage collection routine unsets ->args, ->return, leaving just the lightweight call meta-data behind
     * @var Core_Worker_Call[]
     */
    protected $calls = array();

    /**
     * Call Counter - Used to assign keys in the $calls array
     * Note: Start at 1 because the 0 key is reserved for via classes to use as needed for metadata.
     * @var int
     */
    protected $call_count = 1;

    /**
     * Array of Call ID's of calls currently running on one of the worker processes.
     * Calls are added when we receive a Running ack from a worker, and they're removed when the worker returns
     * or when the $timeout is reached.
     * @var array
     */
    protected $running_calls = array();

    /**
     * Has the shutdown signal been received?
     * @var bool
     */
    protected $shutdown = false;

    /**
     * What is the alias this worker is set to on the Daemon?
     * @var string
     */
    protected $alias = '';

    /**
     * The number of allowed concurrent workers
     * @example Set the worker count using $this->workers();
     * @var int
     */
    protected $workers = 1;

    /**
     * How long, in seconds, can worker methods take before they should be killed?
     * Timeouts are an important tool in call processing guarantees: Workers that are killed or crash cannot notify the
     * daemon of the error. In these cases, the daemon only knows that the job was not acked as complete. In that way,
     * all errors are just timeouts. Your timeout handler will be called and your daemon will have the chance to retry
     * or otherwise handle the failure.
     *
     * Note: If you use your Timeout handler to retry a call, notice the $call->retries count that is kept for you. If your
     * call consistently leads to a fatal error in your worker processes, unlimited retries will result in continued worker
     * failure until the daemon reaches its error tolerance limit and tries to restart itself. Even then it's possible for the
     * queued call to persist until a manual intervention. By limiting retries the daemon can recover from a series of worker
     * fatal errors without affecting the application's stability.
     *
     * Note: There may be deviation in enforcement up to the length of your loop_interval. So if you set this ot "5" and
     * your loop interval is 2.5 second, workers may be allowed to run for up to 7.5 seconds before timing out. This
     * happens because timeouts and the on_return and on_timeout calls are all handled inside the run() loop just before
     * your execute() method is called.
     *
     * @example set a Timeout using $this->timeout();
     * @var float
     */
    protected $timeout = 60;

    /**
     * Callback that's called when a worker completes it's job.
     * @example set a Return Handler using $this->onReturn();
     * @var callable
     */
    protected $on_return;

    /**
     * Callback that's called when a worker timeout is reached. See phpdoc comments on the $timeout property
     * @example set a Timeout Handler using $this->onTimeout();
     * @var callable
     */
    protected $on_timeout;

    /**
     * The ID of this worker pool -- used to address shared IPC resources
     * @var int
     */
    protected $guid;

    /**
     * Array of accumulated error counts. Error thresholds are localized and when reached will
     * raise a fatal error. Generally thresholds on workers are much lower than on the daemon process
     * @var array
     * @todo should we move error counts to the mediator? And the Via object needs to report errors to the mediator? I think probably yes.
     */
    public $error_counts = array (
        'communication' => 0,
        'corruption'    => 0,
        'catchall'      => 0,
    );

    /**
     * Error thresholds for (worker, parent). We want a certain tolerance of errors without restarting the application
     * and these settings can be tweaked per-application.
     *
     * Errors in worker processes have lower thresholds because it is trivial to replace a worker, and workers regularly
     * retire themselves anyway.
     *
     * Communication errors are anything related to a Via object's connection.
     * Corruption errors indicate the Via object was able to get or put a message but that it seemed improperly formatted
     * Catch-all for everything else.
     *
     * @var array
     */
    public $error_thresholds = array (
        'communication' => array(10,  50),
        'corruption'    => array(10,  25),
        'catchall'      => array(10,  25),
    );


    /**
     * Return a valid callback for the supplied $method
     * @abstract
     * @param $method
     */
    protected abstract function get_callback($method);



    public function __construct($alias, Core_Daemon $daemon, Core_IWorkerVia $via) {
        $this->alias            = $alias;
        $this->daemon           = $daemon;
        $this->via              = $via;
        $this->via->mediator    = $this;

        $interval = $this->daemon->loop_interval();
        switch(true) {
            case $interval > 2 || $interval === 0:
                $this->forking_strategy = self::LAZY;
                break;
            case $interval > 1:
                $this->forking_strategy = self::MIXED;
                break;
            default:
                $this->forking_strategy = self::AGGRESSIVE;
                break;
        }
    }

    public function __destruct() {
        // This method intentionally left blank
        // The Daemon destructor calls teardown() on each worker
    }

    public function check_environment(Array $errors = array()) {
        if (function_exists('posix_kill') == false)
            $errors[] = 'The POSIX Extension is Not Installed';

        return $this->via->check_environment($errors);
    }


    public function debug() {

        $that = $this;

        ##
        ## Wrap the current $via object in a DebugShell mediator
        ##
        $this->via = new Core_Lib_DebugShell($this->via);
        $this->via->setup();

        ##
        ## Set callbacks to empower the indentation feature (for easy visual grouping)
        ## and the prompt-prefix (for prompt pid/alias/status identification)
        ##
        $this->via->indent_callback = function($method, $args) {
            switch($method){
                case 'put':
                    if ($args[0] instanceof Core_Worker_Call)
                        return $args[0]->id - 1;    // Start -1 b/c call id 0 is reserved
                    break;
            }

            return 0;
        };

        $alias = $this->alias;
        $this->via->prompt_prefix_callback = function($method, $args) use($alias) {
              return sprintf('%s %s %s', $alias, getmypid(), (Core_Daemon::is('parent')) ? 'D' : 'W');
        };

        ##
        ## Set more specific and informative prompts for certain methods
        ##
        $this->via->prompts['put'] = function($method, $args) {
            $statuses = array(
                Core_Worker_Mediator::UNCALLED   =>  'Daemon sending Call message to Worker',
                Core_Worker_Mediator::RUNNING    =>  'Worker sending "running" ack message to Daemon',
                Core_Worker_Mediator::RETURNED   =>  'Worker sending "return" ack message to Daemon',
            );

            if (!$args[0] instanceof Core_Worker_Call)
                return false;

            return "[Call {$args[0]->id}] " . $statuses[$args[0]->status];
        };

//        $this->via->prompts['get'] = function($method, $args) {
//            $queues = array(
//                Core_Worker_Mediator::WORKER_CALL       =>  'Attach to WORKER_CALL queue and wait for messages',
//                Core_Worker_Mediator::WORKER_RUNNING    =>  'Poll for enqueued "worker is running" acks',
//                Core_Worker_Mediator::WORKER_RETURN     =>  'Poll for enqueued "worker is complete" acks',
//            );
//
//            if (!isset($queues[$args[0]]))
//                return false;
//
//            return $queues[$args[0]];
//        };

        ##
        ## Add any methods to the blacklist that shouldn't trigger a debug prompt. Mostly ones that would just be noise.
        ##
        $this->via->blacklist[] = 'get';

        ##
        ## Add additional command parsers
        ##
        $parsers = array();
        $parsers[] = array(
            'regex'       => '/^call ([A-Z_0-9]+) (.*)?/i',
            'command'     => 'call [f] [a,b..]',
            'description' => 'Call a worker\'s method in the local process with any additional arguments you supply after the method name.',
            'closure'     => function($matches, $printer) use($that) {
                                // Extract any args that may have been passed.
                                // They can be delimited by a comma or space
                                $args = array();
                                if (count($matches) == 3)
                                    $args = explode(' ', str_replace(',', ' ', $matches[2]));

                                // If this is an object mediator, use inline() to grab an instance of the underlying worker object.
                                // Otherwise use the mediator itself as the call context.
                                $context = ($that instanceof Core_Worker_ObjectMediator) ? $that->inline() : $that;
                                $function = array($context, $matches[1]);
                                if (!is_callable($function)) {
                                    $printer('Function Not Callable!');
                                    return false;
                                }

                                $printer("Calling Function $matches[1]()...");
                                $return = call_user_func_array($function, $args);
                                if (is_scalar($return))
                                    $printer("Return: $return", 100);
                                else
                                    $printer("Return: [" . gettype($return) . "]");

                                return false;
                            }
        );

        $this->via->loadParsers($parsers);
    }

    /**
     * If the daemon is in debug-mode, you can set breakpoints in your worker code. If "continue" commands were passed
     * in the debug shell, breakpoint will return true, otherwise false. It's up to you to handle that behavior in your app.
     * Note: If the daemon is not in debug mode, it will always just return true.
     * @param string $prompt    The prompt to display
     * @param integer $indent   The indentation level to use for the prompt, useful for grouping like-prompts together
     * @return bool
     */
    public function breakpoint($prompt = '', $indent = 0) {
        if (!Core_Daemon::get('debug_workers'))
            return true;

        $call = debug_backtrace();
        $call = $call[1];
        $method = sprintf('%s::%s', $call['class'], $call['function']);
        $this->via->prompts[$method] = $prompt;

        if ($indent) {
            $tmp = $this->via->indent_callback;
            $this->via->indent_callback = function() use($indent){
                return $indent;
            };

        }

        $return = true;
        if ($this->via instanceof Core_Lib_DebugShell)
            $return = $this->via->prompt($method, $call['args']);

        if (isset($tmp))
            $this->via->indent_callback = $tmp;

        return $return;
    }

    public function setup() {

        // This class implements both the Task and the Plugin interfaces. Like plugins, this setup() method will be
        // called in the parent process during application init. Like tasks, this setup() method will be called right
        // after the process is forked.

        $that = $this;
        if (Core_Daemon::is('parent')) {

            // Use the ftok() method to create a deterministic memory address.
            // This is a bit ugly but ftok needs a filesystem path so we give it one using the daemon filename and
            // current worker alias.

            @mkdir('/tmp/.phpdaemon');
            $ftok = sprintf('/tmp/.phpdaemon/%s_%s', str_replace('/', '_', $this->daemon->get('filename')), $this->alias);
            if (!touch($ftok))
                $this->fatal_error("Unable to create Worker ID. ftok() failed. Could not write to /tmp directory at {$ftok}");

            $this->guid = ftok($ftok, $this->alias[0]);
            @unlink($this->ftok);

            if (!is_numeric($this->guid))
                $this->fatal_error("Unable to create Worker ID. ftok() failed. Unexpected return value: $this->guid");

//            @todo figure out what i want to do w/ this
//            if (!$this->daemon->get('recover_workers'))
//                $this->via->purge();
            $this->via->setup();
            if ($this->daemon->get('debug_workers'))
                $this->debug();

            $this->fork();
            $this->daemon->on(Core_Daemon::ON_PREEXECUTE,   array($this, 'run'));
            $this->daemon->on(Core_Daemon::ON_IDLE,         array($this, 'garbage_collector'), ceil(30 / ($this->workers * 0.5)));  // Throttle the garbage collector
            $this->daemon->on(Core_Daemon::ON_SIGNAL,       function($signal) use ($that) {
                if ($signal == SIGUSR1)
                    $that->dump();
            });



        } else {
            unset($this->calls, $this->processes, $this->running_calls, $this->on_return, $this->on_timeout, $this->call_count);
            $this->calls = $this->processes = $this->running_calls = array();
            $this->via->setup();

            $this->daemon->on(Core_Daemon::ON_SIGNAL, array($this, 'signal'));
            call_user_func($this->get_callback('setup'));
            $this->log('Worker Process Started');
        }


    }

    /**
     * Called in the Daemon (parent) process during shutdown/restart to shutdown any worker processes.
     * Will attempt a graceful shutdown first and kill -9 only if the worker processes seem to be hanging.
     * @return mixed
     */
    public function teardown() {
        static $state = array();

        if (!Core_Daemon::is('parent'))
            return;

        if ($this->timeout > 0)
            $timeout = min($this->timeout, 60);
        else
            $timeout = 30;

        foreach(array_keys($this->processes) as $pid) {
            if (!isset($state[$pid])) {
                posix_kill($pid, SIGTERM);
                $state[$pid] = time();
                continue;
            }

            if (isset($state[$pid]) && ($state[$pid] + $timeout) < time()) {
                $this->log("Worker '{$pid}' Time Out: Killing Process.");
                posix_kill($pid, SIGKILL);
                unset($state[$pid]);
            }
        }

        // If there are no pending messages, release all shared resources.
        // If there are, then we want to preserve them so we can allow for daemon restarts without losing the call buffer
        if (count($this->processes) == 0) {
            $stat = $this->via->state();
            if ($stat['messages'] > 0) {
                return;
            }

            $this->via->purge();
        }
    }



    /**
     * Fork an appropriate number of daemon processes. Looks at the daemon loop_interval to determine the optimal
     * forking strategy: If the loop is very tight, we will do all the forking up-front. For longer intervals, we will
     * fork as-needed. In the middle we will avoid forking until the first call, then do all the forks in one go.
     * @return mixed
     */
    protected function fork() {

        $processes = count($this->processes);
        if ($this->workers <= $processes)
            return;

        switch ($this->forking_strategy) {
            case self::LAZY:
                $stat = $this->via->state();
                if ($processes > count($this->running_calls) || count($this->calls) == 0 && $stat['messages'] == 0)
                    $forks = 0;
                else
                    $forks = 1;
                break;
            case self::MIXED:
            case self::AGGRESSIVE:
            default:
                $forks = $this->workers - $processes;
                break;
        }

        if (!$this->breakpoint("Forking {$forks} New Worker Processes"))
            return false;

        $errors = array();
        for ($i=0; $i<$forks; $i++) {

            if ($pid = $this->daemon->task($this)) {

                $process = new stdClass();
                $process->microtime = microtime(true);
                $process->job = null;

                // @todo Consider merging these two maps. Maybe a class var array in Core_Worker_Mediator would be cleaner?
                $this->processes[$pid] = $process;
                $this->daemon->worker_pid($this->alias, $pid);
                continue;
            }

            // If the forking failed, we can retry a few times and then fatal-error
            // The most common reason this could happen is the PID table gets full (zombie processes left behind?)
            // or the machine runs out of memory.
            if (!isset($errors[$i])) {
                $errors[$i] = 0;
            }

            if ($errors[$i]++ < 3) {
                $i--;
                continue;
            }

            $this->fatal_error("Could Not Fork: See PHP error log for an error code and more information.");
        }
    }

    /**
     * Called in the Daemon to inform a worker one of it's forked processes has ed
     * @param int $pid
     * @param int $status
     * @return void
     */
    public function reap($pid, $status) {
        static $failures = 0;
        static $last_failure = null;

        // Keep track of processes that fail within the first 30 seconds of being forked.
        if (isset($this->processes[$pid]) && time() - $this->processes[$pid]->microtime < 30) {
            $failures++;
            $last_failure = time();
        }

        if ($failures == 5) {
            $this->fatal_error("Unsuccessful Fork: Recently forked processes are continuously failing. See error log for additional details.");
        }

        // If there hasn't been a failure in 90 seconds, reset the counter.
        // The counter only exists to prevent an endless fork loop due to child processes fatal-erroring right after a successful fork.
        // Other types of errors will be handled elsewhere
        if ($failures && time() - $last_failure > 90) {
            $failures = 0;
            $last_failure = null;
        }

        unset($this->processes[$pid]);
    }

    /**
     * Called in each iteration of your daemon's event loop. Listens for worker Acks and enforces timeouts when applicable.
     * Note: Called only in the parent (daemon) process, attached to the Core_Daemon::ON_PREEXECUTE event.
     *
     * @return void
     */
    public function run() {

        if (empty($this->calls))
            return;

        try {

            // If there are any callbacks registered (onReturn, onTimeout, etc), we will pass
            // the call struct and this $logger closure to them
            $that = $this;
            $logger = function($message) use($that) {
                $that->log($message);
            };

            while($call = $this->via->get(self::WORKER_RUNNING)) {

                $this->running_calls[$call->id] = true;

                // It's possible the process exited after sending this ack, ensure it's still valid.
                if (isset($this->processes[$call->pid]))
                    $this->processes[$call->pid]->job = $call->id;

                $this->log('Job ' . $call->id . ' Is Running');
            }

            while($call = $this->via->get(self::WORKER_RETURN)) {

                unset($this->running_calls[$call->id]);
                if (isset($this->processes[$call->pid]))
                    $this->processes[$call->pid]->job = $call->id;

                $on_return = $this->on_return;
                if (is_callable($on_return))
                    call_user_func($on_return, $call, $logger);
                else
                    $this->log('No onReturn Callback Available');

                $this->log('Job ' . $call->id . ' Is Complete');
            }

            // Enforce Timeouts
            // Timeouts will either be simply that the worker is taking longer than expected to return the call,
            // or the worker actually fatal-errored and killed itself.
            if ($this->timeout > 0) {
                foreach(array_keys($this->running_calls) as $call_id) {
                    $call = $this->calls[$call_id];
                    if ($call->runtime() > $this->timeout) {

                        if (!$this->breakpoint("[Call {$call_id}] Enforcing timeout at runtime: " . $call->runtime(), $call_id-1))
                            continue;

                        $this->log("Enforcing Timeout on Call $call_id in pid " . $call->pid);
                        @posix_kill($call->pid, SIGKILL);
                        unset($this->running_calls[$call_id], $this->processes[$call->pid]);
                        $call->timeout();

                        $on_timeout = $this->on_timeout;
                        if (is_callable($on_timeout))
                            call_user_func($on_timeout, $call, $logger);
                    }
                }
            }

            // If we've killed all our processes -- either timeouts or maybe they fatal-errored -- and we have pending
            // calls in the queue, fork()
            if (count($this->processes) == 0) {
                $stat = $this->via->state();
                if ($stat['messages'] > 0) {
                    $this->fork();
                }
            }

        } catch (Exception $e) {
            $this->log(__METHOD__ . ' Failed: ' . $e->getMessage(), true);
        }
    }

    /**
     * Starts the event loop in the Forked process that will listen for messages
     * Note: Runs only in the worker (forked) process
     * @return void
     */
    public function start() {

        while(!Core_Daemon::is('parent') && !$this->shutdown) {

            // Give the CPU a break - Sleep for 1/20 a second.
            usleep(50000);

            // Define automatic restart intervals. We want to add some entropy to avoid having all worker processes
            // in a pool restart at the same time. Use a very crude technique to create a random number along a normal distribution.
            $entropy = round((mt_rand(-1000, 1000) + mt_rand(-1000, 1000) + mt_rand(-1000, 1000)) / 100, 0);

            $max_jobs       = $this->call_count++ >= (25 + $entropy);
            $min_runtime    = $this->daemon->runtime() >= (60 * 5);
            $max_runtime    = $this->daemon->runtime() >= (60 * 30 + $entropy * 10);
            $this->shutdown = ($max_runtime || $min_runtime && $max_jobs);

            if ($this->shutdown)
                $this->log("Recycling Worker...");

            if (mt_rand(1, 5) == 1)
                $this->garbage_collector();

            if ($call = $this->via->get(self::WORKER_CALL, true)) {
                try {
                    // If the current via supports it, calls can be cancelled while they are enqueued
                    if ($call->status == self::CANCELLED) {
                        $this->log("Call {$call->id} Cancelled By Mediator -- Skipping...");
                        continue;
                    }

                    if (!$this->breakpoint(sprintf('[Call %s] Calling method in worker', $call->id, ($this instanceof Core_Worker_ObjectMediator) ? $call->method : $this->alias), $call->id - 1)) {
                        $call->cancelled();
                        continue;
                    }

                    $call->running();
                    if (!$this->via->put($call))
                        $this->log("Call {$call->id} Could Not Ack Running.");

                    $call->returned(call_user_func_array($this->get_callback($call->method), $call->args));
                    if (!$this->via->put($call))
                        $this->log("Call {$call->id} Could Not Ack Complete.");
                }
                catch (Exception $e) {
                    $this->error($e->getMessage());
                }
            }

        }
    }

    /**
     * Mediate all calls to methods on the contained $object and pass them to instances of $object running in the background.
     * @param Core_Worker_Call $call
     * @return integer   A unique-per-process identifier for the call OR false on error. That ID can be used to interact
     *                   with the call, eg checking the call status.
     */
    protected function call(Core_Worker_Call $call) {

        if (!$this->breakpoint(sprintf('[Call %s] Call to %s()', $call->id, ($this instanceof Core_Worker_ObjectMediator) ? $call->method : $this->alias), $call->id - 1)) {
            $call->cancelled();
            return false;
        }

        try {
            $this->calls[$call->id] = $call;
            if ($this->via->put($call)) {
                $call->called();
                $this->fork();
                return $call->id;
            }
        } catch (Exception $e) {
            $this->log('Call Failed: ' . $e->getMessage(), true);
        }

        // The call failed -- args could be big so trim it back proactively, leaving
        // the call metadata the same way the GC process works
        $call->args = null;
        return false;
    }

    /**
     * Get the worker ID
     * @return int
     */
    public function guid() {
        return $this->guid;
    }

    /**
     * Satisfy the debugging interface in case there are user-created prompt() calls in their workers
     */
    public function prompt($prompt, $args = null, Closure $on_interrupt = null) {
        return true;
    }

    /**
     * Intercept method calls on worker objects and pass them to the worker processes
     * @param $method
     * @param $args
     * @return bool
     * @throws Exception
     */
    public function __call($method, $args) {
        if (!in_array($method, $this->methods))
            throw new Exception(__METHOD__ . " Failed. Method `{$method}` is not callable.");

        $this->call_count++;
        return $this->call(new Core_Worker_Call($this->call_count, $method, $args));
    }

    public function call_count() {
        return count($this->call_count);
    }

    /**
     * Return the requested call from the local call cache if it exists
     * @return Core_Worker_Call
     */
    public function get_struct($call_id) {
        if (isset($this->calls[$call_id]))
            return $this->calls[$call_id];

        return null;
    }

    /**
     * Set the supplied $call into the local call cache, doing any merging with a previously-cached version is necessary
     * @param Core_Worker_Call $call
     */
    public function set_struct(Core_Worker_Call $call) {
        if(isset($this->calls[$call->id]))
            $this->calls[$call->id] = $call->merge($this->calls[$call->id]);
        else
            $this->calls[$call->id] = $call;
    }

    /**
     * Periodically garbage-collect call structs: Keep the metadata but remove the (potentially large) args and return values
     * The parent will also ensure any GC'd items are removed from shared memory though in normal operation they're deleted when they return
     * Essentially a mark-and-sweep strategy. The garbage collector will also do some analysis on calls that seem frozen
     * and attempts to retry them when appropriate.
     * @return void
     */
    public function garbage_collector() {
        $called = array();
        foreach ($this->calls as $call_id => &$call) {
            if ($call->gc() && Core_Daemon::is('parent'))
                $this->via->drop($call_id);

            if ($call->status == self::CALLED)
                $called[] = $call_id;
        }

        if (!Core_Daemon::is('parent') || count($called) == 0)
            return;

        // We need to determine if we have any "dropped calls" in CALLED status. This could happen in a few scenarios:
        // 1) There was a silent message-queue failure and the item was never presented to workers.
        // 2) A worker received the message but fatal-errored before acking.
        // 3) A worker received the message but a message queue failure prevented the acks being sent.
        // @todo On the off chance #3 was true, the job may have been finished. Give a try to checking the via object for that and processing the result.

        // Look at all the jobs recently acked and determine which of them was called first. Get the time of that call as the $cutoff.
        // Any structs in CALLED status that were called prior to that $cutoff have been dropped and will be requeued.

        $cutoff = $this->calls[$this->call_count]->time[self::CALLED];
        foreach($this->processes as $process) {
            if ($process->job === null && time() - $process->microtime < 30)
                return; // Give processes time to ack their first job

            if ($process->job !== null)
                $cutoff = min($cutoff, $this->calls[$process->job]->time[self::CALLED]);
        }

        foreach($called as $call_id) {
            $call = $this->calls[$call_id];
            if ($call->time[self::CALLED] > $cutoff)
                continue;

            // If there's a retry count above our threshold log and skip to avoid endless requeueing
            if ($call->retries > 3) {
                $call->cancelled();
                $this->error("Dropped Call. Requeue threshold reached. Call {$call->id} will not be requeued.");
                continue;
            }

            // Requeue the message. If somehow the original message is still out there the worker will compare timestamps
            // and mark the original call as CANCELLED.
            $this->log("Dropped Call. Requeuing Call {$call->id} To `{$call->method}`");

            $call->retries++;
            $call->errors = 0;
            $this->call($call);
        }
    }

    /**
     * Report an error. Keep a count of error types and act appropriately when thresholds have been met.
     * @param $type
     * @return void
     */
    public function count_error($type) {
        $this->error_counts[$type]++;
        if ($this->error_counts[$type] > $this->error_thresholds[$type][(int)Core_Daemon::is('parent')])
            $this->fatal_error("IPC '$type' Error Threshold Reached");
        else
            $this->log("Incrementing Error Count for {$type} to " . $this->error_counts[$type]);
    }

    /**
     * Increase back-off delays in an exponential way up to a certain plateau.
     * @param $delay
     * @param $try
     */
    public function backoff($delay, $try) {
        return $delay * pow(2, min(max($try, 1), 8)) - $delay;
    }

    /**
     * If your worker object implements an execute() method, it can be called in the daemon using $this->MyAlias()
     * @return bool
     */
    public function __invoke() {
        return $this->__call('execute', func_get_args());
    }

    /**
     * Attached to the Daemon's ON_SIGNAL event
     * @param $signal
     */
    public function signal($signal) {
        switch ($signal)
        {
            case SIGUSR1:
                // kill -10 [pid]
                $this->dump();
                break;
            case SIGHUP:
                if (!Core_Daemon::is('parent'))
                    $this->log("Restarting Worker Process...");

            case SIGINT:
            case SIGTERM:
                $this->shutdown = true;
                break;

        }
    }

    /**
     * Dump runtime stats in tabular fashion to the log.
     * @return void
     */
    public function dump() {

        $status_labels = array(
            self::UNCALLED => 'Uncalled',
            self::CALLED   => 'Called',
            self::RUNNING  => 'Running',
        );

        // Compute the raw duration data for each call, grouped by method name and status
        // (See how long we were in CALLED status waiting to run, how long we were RUNNING, etc)
        $durations = array();
        foreach($this->calls as $call) {
            if (!isset($durations[$call->method]))
                $durations[$call->method] = array();

            foreach(array(self::CALLED, self::RUNNING) as $status) {
                if (!isset($durations[$call->method][$status]))
                    $durations[$call->method][$status] = array();

                if (isset($call->time[$status+1]))
                    $durations[$call->method][$status][] = max(round($call->time[$status+1] - $call->time[$status], 5), 0);
            }
        }

        // Write out the header
        // Then write out the data table with an indent

        $out = array();
        $out[] = "---------------------------------------------------------------------------------------------------";
        $out[] = "Worker Runtime Statistics";
        $out[] = "---------------------------------------------------------------------------------------------------";
        $out[] = '';
        $this->log(implode("\n", $out));

        $out = array();
        $out[] = 'Method Duration      Status           Mean     Median      Count';
        $out[] = '================================================================';

        foreach($durations as $method => $method_data) {
            foreach ($method_data as $status => $status_data) {
                $mean = $median = 0;
                sort($status_data);
                if ($count  = count($status_data)) {
                    $mean   = round(array_sum($status_data) / $count, 5);
                    $median = round($status_data[intval($count / 2)], 5);
                }
                $out[]  = sprintf('%s %s %s %s %s',
                    str_pad(substr($method, 0, 20), 20, ' ', STR_PAD_RIGHT),
                    str_pad($status_labels[$status], 10, ' ', STR_PAD_RIGHT),
                    str_pad(number_format($mean, 5, '.', ''), 10, ' ', STR_PAD_LEFT),
                    str_pad(number_format($median, 5, '.', ''), 10, ' ', STR_PAD_LEFT),
                    str_pad(number_format($count, 0), 10, ' ', STR_PAD_LEFT)
                );
            }
        }

        $out[] = '';
        $out[] = 'Error Type      Count';
        $out[] = '=====================';

        foreach($this->error_counts as $type => $count) {
            $out[] = sprintf('%s %s',
                str_pad(ucfirst($type), 15),
                str_pad(number_format($count, 0), 5, ' ', STR_PAD_LEFT)
            );
        }

        $this->log(implode("\n", $out), 1);
        $this->log('');
    }



    /**
     * Write do the Daemon's event log
     *
     * Part of the Worker API - Use from your workers to log events to the Daemon event log
     *
     * @param $message
     * @return void
     */
    public function log($message, $indent = 0) {
        $this->daemon->log("$message", $this->alias, $indent);
    }

    /**
     * Dispatch ON_ERROR event and write an error message to the Daemon's event log
     *
     * Part of the Worker API - Use from your workers to log an error message.
     *
     * @param $message
     * @return void
     */
    public function error($message) {
        $this->daemon->error("$message", $this->alias);
    }

    /**
     * Dispatch ON_ERROR event, write an error message to the event log, and restart the worker.
     *
     * Part of the Worker API - Use from your worker to log a fatal error message and restart the current process.
     *
     * @param $message
     * @return void
     */
    public function fatal_error($message) {
        $this->daemon->fatal_error("$message\nFatal Error: Worker process will restart", $this->alias);
    }

    /**
     * Access daemon properties from within your workers
     *
     * Part of the Worker API - Use from your worker to access data set on your Daemon class
     *
     * @example [inside a worker class] $this->mediator->daemon('dbconn');
     * @example [inside a worker class] $ini = $this->mediator->daemon('ini'); $ini['database']['password']
     * @param $property
     * @return mixed
     */
    public function daemon($property) {
        if (isset($this->daemon->{$property}) && !is_callable($this->daemon->{$property}))
            return $this->daemon->{$property};

        return null;
    }



    /**
     * Re-run a previous call by passing in the call's struct.
     * Note: When calls are re-run a retry=1 property is added, and that is incremented for each re-call. You should check
     * that value to avoid re-calling failed methods in an infinite loop.
     *
     * Part of the Daemon API - Use from your daemon to retry a given call
     *
     * @example You set a timeout handler using onTimeout. The worker will pass the timed-out call to the handler as a
     * stdClass object. You can re-run it by passing the object here.
     * @param stdClass $call
     * @return bool
     */
    public function retry(Core_Worker_Call $call) {
        if (empty($call->method))
            throw new Exception(__METHOD__ . " Failed. A valid call struct is required.");

        $this->log("Retrying Call {$call->id} To `{$call->method}`");

        $call->retries++;
        $call->errors = 0;
        return $this->call($call);
    }

    /**
     * Determine the status of a given call. Call ID's are returned when a job is called. Important to note that
     * call ID's are only unique within this worker and this execution.
     *
     * Part of the Daemon API - Use from your daemon to determine the status of a given call
     *
     * @param integer $call_id
     * @return int  Return a status int - See status constants in this class
     */
    public function status($call_id) {
        if (isset($this->calls[$call_id]))
            return $this->calls[$call_id]->status;

        return null;
    }

    /**
     * Set a callable that will called whenever a timeout is enforced on a worker.
     * The offending $call stdClass will be passed-in. Can be passed to retry() to re-try the call. Will have a
     * `retries=N` property containing the number of times it's been sent thru retry().
     *
     * Part of the Daemon API - Use from your daemon to set a Timeout handler
     *
     * @param callable $on_timeout
     * @throws Exception
     */
    public function onTimeout($on_timeout) {
        if (!is_callable($on_timeout))
            throw new Exception(__METHOD__ . " Failed. Callback or Closure expected.");

        $this->on_timeout = $on_timeout;
    }

    /**
     * Set a callable that will be called when a worker method completes.
     * The $call stdClass will be passed-in -- with a `return` property.
     *
     * Part of the Daemon API - Use from your daemon to set a Return handler
     *
     * @param callable $on_return
     * @throws Exception
     */
    public function onReturn($on_return) {
        if (!is_callable($on_return))
            throw new Exception(__METHOD__ . " Failed. Callback or Closure expected.");

        $this->on_return = $on_return;
    }

    /**
     * Set the timeout for methods called on this worker. When a timeout happens, the onTimeout() callback is called.
     *
     * Part of the Daemon API - Use from your daemon to set a timeout for all worker calls.
     *
     * @param $timeout
     * @throws Exception
     */
    public function timeout($timeout) {
        if (!is_numeric($timeout))
            throw new Exception(__METHOD__ . " Failed. Numeric value expected.");

        $this->timeout = $timeout;
    }

    /**
     * Set the number of concurrent workers in the pool. No limit is enforced, but processes are expensive and you should use
     * the minimum number of workers necessary. Too few workers will result in high latency situations and bigger risk
     * that if your application needs to be restarted you'll lose buffered calls.
     *
     * In `lazy` forking strategy, the processes are forked one-by-one, as needed. This is avoided when your loop_interval
     * is very short (we don't want to be forking processes if you need to loop every half second, for example) but it's
     * the most ideal setting. Read more about the forking strategy for more information.
     *
     * Part of the Daemon API - Use from your daemon to set the number of concurrent asynchronous worker processes.
     *
     * @param int $workers
     * @throws Exception
     */
    public function workers($workers) {
        if (!is_int($workers))
            throw new Exception(__METHOD__ . " Failed. Integer value expected.");

        $this->workers = $workers;
    }

    /**
     * Does the worker have at least one idle process?
     *
     * Part of the Daemon API - Use from your daemon to determine if any of your daemon's worker processes are idle
     *
     * @example Use this to implement a pattern where there is always a background worker working. Suppose your daemon writes results to a file
     *          that you want to upload to S3 continuously. You could create a worker to do the upload and set ->workers(1). In your execute() method
     *          if the worker is idle, call the upload() method. This way it should, at all times, be uploading the latest results.
     *
     * @return bool
     */
    public function is_idle() {
        return $this->workers > count($this->running_calls);
    }

}
