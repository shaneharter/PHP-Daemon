<?php
/**
 * Created by JetBrains PhpStorm.
 * User: shane
 * Date: 4/10/12
 * Time: 2:37 PM
 * To change this template use File | Settings | File Templates.
 */
abstract class Core_Worker_Mediator
{
    /**
     * IPC Message Queue message types
     */
    const WORKER_CALL = 1;
    const WORKER_RUNNING = 2;
    const WORKER_RETURN = 3;

    /**
     * Call Statuses
     */
    const UNCALLED = 0;
    const CALLED = 1;
    const RUNNING = 2;
    const RETURNED = 3;
    const TIMEOUT = 10;

    /**
     * Forking Strategies
     */
    const LAZY = 1;
    const MIXED = 2;
    const AGGRESSIVE = 3;

    /**
     * The forking strategy of the Worker
     *
     * @example self::LAZY
     * In Lazy forking, the first worker is forked the first time a worker method is called. At that time, only one process is forked,
     * regardless of how many concurrent workers you've set using workers(). When subsequent worker methods are called,
     * additional workers will be forked (up to the workers() limit), if the existing workers are currently busy. Work prefers to be
     * assigned to existing workers. This strategy is used when daemon loop_intervals are over 2 seconds. The fork itself could cause shorter loop intervals to run too long.
     *
     * @example self::MIXED
     * In Mixed forking, the first worker is forked the first time a worker method is called. At that time, ALL worker processes
     * are forked, up to the limit set in workers().
     *
     * This strategy is used when daemon loop_intervals in intervals shorter than we use for self::LAZY. We want to save time/RAM by
     * forking only if necessary, but when we fork, we want to do it all at once.
     *
     * @example self::AGGRESSIVE
     * In Aggressive forking, all the worker processes are forked during daemon startup.
     * This strategy is used for short loop intervals.
     *
     * @var int
     */
    protected $forking_strategy = self::MIXED;

    /**
     * @var Core_Daemon
     */
    protected $daemon;

    /**
     * Running worker processes
     * @var array
     */
    protected $processes = array();

    /**
     * Methods available on the $object
     * @var array
     */
    protected $methods = array();

    /**
     * All Calls (Garbage collection will occur periodically)
     * @var array
     * @todo Garbage Collection
     */
    protected $calls = array();

    /**
     * Calls currently running on one of the worker processes.
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
     * A handle to the IPC message queue
     * @var Resource
     */
    protected $queue;

    /**
     * A handle to the IPC Shared Memory resource
     * @var Resource
     */
    protected $shm;

    /**
     * The number of allowed concurrent workers
     * @var int
     */
    protected $workers = 1;

    /**
     * How long, in seconds, can worker methods take before being killed?
     * Note: There may be deviation in enforcement up to the length of your loop_interval. So if you set this ot "5" and
     * your loop interval is 2.5 second, workers may be allowed to run for up to 7.5 seconds before timing out.
     * Note: You can set a callback using $this->onTimeout that will be called when a worker times-out.
     * @var decimal
     */
    protected $timeout = 0;

    /**
     * Callback that's called when a worker completes it's job.
     * @example set using $this->onReturn();
     * @var Callable
     */
    protected $on_return;

    /**
     * Callback that's called when a worker times-out
     * @example set using $this->onTimeout();
     * @var Callable
     */
    protected $on_timeout;

    /**
     * Is the current instance the Parent (daemon-side) mediator, or the Child (worker-side) mediator?
     * @var bool
     */
    protected $is_parent = true;

    /**
     * How big, at any time, can the IPC shared memory allocation be.
     * Default is 1MB. May need to be increased if you are passing very large datasets as Arguments and Return values.
     * @var float
     */
    protected $memory_limit;

    /**
     * The ID of this worker pool -- used to
     * @var int
     */
    protected $id;



    /**
     * Return a valid callback for the supplied $call
     * @abstract
     * @param $call
     */
    protected abstract function getCallback(stdClass $call);



    public function __construct($alias, Core_Daemon $daemon) {
        $this->alias = $alias;
        $this->daemon = $daemon;
        $this->memory_limit = 1024 * 1000;

        $interval = $this->daemon->interval();
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

    public function setup() {

        if ($this->is_parent) {
            do {
                $this->id = mt_rand(999999, 99999999);
            } while(msg_queue_exists($this->id) == 1);

            if ($this->forking_strategy == self::AGGRESSIVE)
                $this->fork();

            $this->daemon->on(Core_Daemon::ON_RUN, array($this, 'run'));
        } else {
            $this->daemon->on(Core_Daemon::ON_SIGNAL, array($this, 'signal'));
            $this->log('Worker Process Started...');
        }

        $this->queue = msg_get_queue($this->id, 0666);
        $this->shm = shm_attach($this->id, $this->memory_limit, 0666);
    }

    public function check_environment(Array $errors = array()) {
        if (function_exists('posix_kill') == false)
            $errors[] = 'The PCNTL Extension is Not Installed';

        return $errors;
    }

    /**
     * Fork an appropriate number of daemon processes. Looks at the daemon loop_interval to determine the optimal
     * forking strategy: If the loop is very tight, we will do all the forking up-front. For longer intervals, we will
     * fork as-needed. In the middle we will avoid forking until the first call, then do all the forks in one go.
     * @return mixed
     */
    public function fork() {
        $processes = count($this->processes);
        if ($this->workers <= $processes)
            return;

        switch ($this->forking_strategy) {
            case self::LAZY:
                if ($processes > count($this->running_calls))
                    $forks = 0;
                else
                    $forks = 1;
                break;
            case self::MIXED:
                $forks = $this->workers - $processes;
                break;
            case self::AGGRESSIVE:
            default:
                $forks = $this->workers;
                break;
        }

        for ($i=0; $i<$forks; $i++) {
            $pid = $this->daemon->fork(array($this, 'start'), array(), true, $this->alias);
            $this->processes[$pid] = microtime(true);
        }
    }

    /**
     * Called in the Daemon to inform a worker one of it's forked processes has exited
     * @param int $pid
     * @param int $status
     * @return void
     */
    public function reap($pid, $status) {
        unset($this->processes[$pid]);
    }


    /**
     * Called in the parent process, once per each iteration in the daemons run() loop. Checks messages queues for information from worker
     * processes, and enforces timeouts when applicable.
     * Note: Called only in the parent (daemon) process
     * @return void
     */
    public function run() {

        $message_type = $message = $message_error = null;
        if (msg_receive($this->queue, self::WORKER_RUNNING, $message_type, $this->memory_limit, $message, true, MSG_IPC_NOWAIT, $message_error)) {
            $call_id = $this->message_decode($message);
            $this->running_calls[$call_id] = true;
            $this->log('Job ' . $call_id . ' Is Running');
        } else {
            $this->message_error($message_error);
        }

        $message_type = $message = $message_error = null;
        if (msg_receive($this->queue, self::WORKER_RETURN, $message_type, $this->memory_limit, $message, true, MSG_IPC_NOWAIT, $message_error)) {
            $call_id = $this->message_decode($message);
            $call = $this->calls[$call_id];

            unset($this->running_calls[$call_id]);
            if (is_callable($this->on_return))
                $this->on_return($call->return);

            $this->log('Job ' . $call_id . ' Is Complete');
        } else {
            $this->message_error($message_error);
        }

        if ($this->timeout > 0) {
            $now = microtime(true);
            foreach(array_keys($this->running_calls) as $call_id) {
                $call = $this->calls[$call_id];
                if ($now > ($call->time[self::RUNNING] + $this->timeout)) {
                    posix_kill($call->pid, SIGKILL);
                    unset($this->running_calls[$call_id]);
                    $call->status = self::TIMEOUT;

                    if (is_callable($this->on_timeout))
                        call_user_func($this->on_timeout, $call);

                }
            }

        }

    }

    /**
     * Starts the event loop in the Forked process that will listen for messages
     * Note: Run only in the child (forked) process
     * @return void
     */
    public function start() {

        $this->is_parent = false;
        $this->setup();

        while($this->shutdown == false) {
            $message_type = $message = $message_error = null;
            if (msg_receive($this->queue, self::WORKER_CALL, $message_type, $this->memory_limit, $message, true, 0, $message_error)) {
                try {
                    $call_id = $this->message_decode($message);
                    $call = $this->calls[$call_id];

                    $call->pid = getmypid();
                    if ($this->message_encode($call_id) !== true) {
                        $this->log("Call {$call_id} Could Not Ack Running.");
                    }

                    $call->return = call_user_func_array($this->getCallback($call), $call->args);

                    if ($this->message_encode($call_id) !== true) {
                        $this->log("Call {$call_id} Could Not Ack Complete.");
                    }
                }
                catch (Exception $e) {
                    $this->log($e->getMessage());
                }

            } else {
                $this->message_error($message_error);
            }

        }
    }

    /**
     * Attached to the Daemon's ON_SIGNAL event
     * @param $signal
     */
    public function signal($signal) {
        switch ($signal)
        {
            case SIGINT:
            case SIGTERM:
                $this->shutdown = true;
                break;

            case SIGHUP:
                $this->log("Restarting Worker Process...");
                $this->shutdown = true;
        }
    }

    /**
     * Access daemon properties from within your workers
     * @example [inside a worker class] $this->mediator->daemon('dbconn');
     * @example [inside a worker class] $ini = $this->mediator->daemon('ini'); $ini['database']['password']
     * @param $property
     */
    public function daemon($property) {
        if (isset($this->daemon->{$property}) && !is_callable($this->daemon->{$property})) {
            return $this->daemon->{$property};
        }
        return null;
    }

    /**
     * Handle Message Queue errors
     * @param $error_code
     */
    protected function message_error($error_code) {
        $ignored_errors = array(
            4,  // System Interrupt
            42, // No message of desired type
            0,  // Success
        );

        if (!in_array($error_code, $ignored_errors))
            $this->log("Message Queue Error [$error_code] " . posix_strerror($error_code), true);
    }

    /**
     * Send messages for the given $call_id to the right queue based on that call's state. Writes call data
     * to shared memory at the address specified in the message.
     * @param $call_id
     * @return bool
     */
    protected function message_encode($call_id) {

        $call = $this->calls[$call_id];

        $queue_lookup = array(
            self::CALLED    => self::WORKER_CALL,
            self::RUNNING   => self::WORKER_RUNNING,
            self::RETURNED  => self::WORKER_RETURN
        );

        $message = array('call' => $call->id);
        $message_error = null;

        $call->status++;
        $call->time[$call->status] = microtime(true);
        shm_put_var($this->shm, $call_id, $call);

        if (msg_send($this->queue, $queue_lookup[$call->status], $message, true, false, $message_error)) {
            //$this->log("Message Sent to Queue " . $queue_lookup[$call->status]);
            return true;
        }

        $this->message_error($message_error);
        return false;
    }

    /**
     * Decode the supplied-message. Pulls in data from the shared memory address referenced in the message.
     * @param array $message
     * @return mixed
     * @throws Exception
     */
    protected function message_decode(Array $message) {

        $call = null;
        if ($call_id = $message['call'])
            $call = shm_get_var($this->shm, $call_id);

        if (!is_object($call))
            throw new Exception(__METHOD__ . " Failed. Expected stdClass object in {$this->id}:{$call_id}. Given: " . gettype($call));

        $this->calls[$call_id] = $call;
        shm_remove_var($this->shm, $call_id);
        return $call_id;
    }

    /**
     * Write do the Daemon's event log
     * @param $message
     * @param bool $is_error
     */
    public function log($message, $is_error = false) {
        $this->daemon->log("Worker [{$this->alias}] $message\n", $is_error);
    }


    /**
     * Re-run a previous call by passing in the call's struct.
     * Note: When calls are re-run a retry=1 property is added, and that is incremented for each re-call. You should check
     * that value to avoid re-calling failed methods in an infinite loop.
     *
     * @example You set a timeout handler using onTimeout. The worker will pass the timed-out call to the handler as a
     * stdClass object. You can re-run it by passing the object here.
     * @param stdClass $call
     */
    public function retry(stdClass $call) {
        if (empty($call->method))
            throw new Exception(__METHOD__ . " Failed. A valid call struct is required.");

        return $this->__call($call->method, $call->args, ++$call->retries);
    }

    /**
     * Mediate all calls to methods on the contained $object and pass them to instances of $object running in the background.
     * @param $method
     * @param $args
     * @return bool
     * @throws Exception
     */
    public function __call($method, $args) {

        $retries = 0;

        // Horrible hack to support retries. Would be better if we could just add an optional 3rd param to this method
        // to indicate a retry but we can't. And we need the struct to have a retry count so implementations can avoid
        // infinite loops. So we hack this all by having the retry() method pass an entire stdClass call struct as $method
        if (is_object($method) && !empty($method->method)) {
            $args    = $method->args;
            $retries = $method->retries;
            $method  = $method->method;
        }

        if (!in_array($method, $this->methods))
            throw new Exception(__METHOD__ . " Failed. Method `{$method}` is not callable.");

        $this->fork();

        $call = new stdClass();
        $call->method        = $method;
        $call->args          = $args;
        $call->status        = self::UNCALLED;
        $call->time          = array(microtime(true));
        $call->pid           = null;
        $call->id            = count($this->calls) + 1; // We use this ID for shm keys which cannot be 0
        $call->retries       = $retries;
        $this->calls[$call->id] = $call;

        // @todo handle call errors intelligently...
        return $this->message_encode($call->id) === true;
    }

    /**
     * If your worker object implements an execute() method, it can be called in the daemon using $this->MyAlias()
     * @param $args
     * @return bool
     */
    public function __invoke($args)
    {
        return $this->__call('execute', $args);
    }

    /**
     * Set a callable that will called whenever a timeout is enforced on a worker.
     * The offending $call stdClass will be passed-in. Can be passed to retry() to re-try the call. Will have a
     * `retries=N` property containing the number of times it's been sent thru retry().
     * @param $on_timeout
     * @throws Exception
     */
    public function onTimeout($on_timeout)
    {
        if (!is_callable($on_timeout))
            throw new Exception(__METHOD__ . " Failed. Callback or Closure expected.");

        $this->on_timeout = $on_timeout;
    }

    /**
     * Set a callable that will be called when a worker method completes.
     * The $call stdClass will be passed-in -- with a `return` property.
     * @param $on_return
     * @throws Exception
     */
    public function onReturn($on_return)
    {
        if (!is_callable($on_return))
            throw new Exception(__METHOD__ . " Failed. Callback or Closure expected.");

        $this->on_return = $on_return;
    }

    /**
     * Set the timeout for methods called on this worker. When a timeout happens, the onTimeout() callback is called.
     * @param $timeout
     * @throws Exception
     */
    public function timeout($timeout)
    {
        if (!is_numeric($timeout))
            throw new Exception(__METHOD__ . " Failed. Numeric value expected.");

        $this->timeout = $timeout;
    }

    /**
     * Set the number of concurrent workers. No limit is hard-coded, but processes are expensive and you should use
     * the minimum number of workers necessary. In `lazy` forking strategy, the processes are forked one-by-one, as
     * needed. This is avoided when your loop_interval is very short (we don't want to be forking processes if you
     * need to loop every half second, for example) but it's the most ideal setting. Read more about the forking strategy
     * for more information.
     * @param int $workers
     * @throws Exception
     */
    public function workers($workers)
    {
        if (!is_int($workers))
            throw new Exception(__METHOD__ . " Failed. Integer value expected.");

        $this->workers = $workers;
    }    
    

}
