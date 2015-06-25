<?php

declare(ticks = 5);

/**
 * Daemon Base Class - Extend this to build daemons.
 * @uses PHP 5.3 or Higher
 * @author Shane Harter
 * @link https://github.com/shaneharter/PHP-Daemon
 * @see https://github.com/shaneharter/PHP-Daemon/wiki/Daemon-Startup-Order-Explained
 * @singleton
 * @abstract
 */
abstract class Core_Daemon
{
    /**
     * The application will attempt to restart itself it encounters a recoverable fatal error after it's been running
     * for at least this many seconds. Prevents killing the server with process forking if the error occurs at startup.
     * @var integer
     */
    const MIN_RESTART_SECONDS = 10;

    /**
     * Events can be attached to each state using the on() method
     * @var integer
     */
    const ON_ERROR          = 0;    // error() or fatal_error() is called
    const ON_SIGNAL         = 1;    // the daemon has received a signal
    const ON_INIT           = 2;    // the library has completed initialization, your setup() method is about to be called. Note: Not Available to Worker code.
    const ON_PREEXECUTE     = 3;    // inside the event loop, right before your execute() method
    const ON_POSTEXECUTE    = 4;    // and right after
    const ON_FORK           = 5;    // in a background process right after it has been forked from the daemon
    const ON_PIDCHANGE      = 6;    // whenever the pid changes -- in a background process for example
    const ON_IDLE           = 7;    // called when there is idle time at the end of a loop_interval, or at the idle_probability when loop_interval isn't used
    const ON_REAP           = 8;    // notification from the OS that a child process of this application has exited
    const ON_SHUTDOWN       = 10;   // called at the top of the destructor

    /**
     * An array of instructions that's displayed when the -i param is passed to the application.
     * Helps sysadmins and users of your daemons get installation correct. Guide them to set
     * correct permissions, supervisor/monit setup, crontab entries, init.d scripts, etc
     * @var Array
     */
    protected $install_instructions = array();

    /**
     * The frequency of the event loop. In seconds.
     *
     * In timer-based applications your execute() method will be called every $loop_interval seconds. Any remaining time
     * at the end of an event loop iteration will dispatch an ON_IDLE event and your application will sleep(). If the
     * event loop takes longer than the $loop_interval an error will be written to your application log.
     *
     * @example $this->loop_interval = 300;     // execute() will be called once every 5 minutes
     * @example $this->loop_interval = 0.5;     // execute() will be called 2 times every second
     * @example $this->loop_interval = 0;       // execute() will be called immediately -- There will be no sleep.
     *
     * @var float The interval in Seconds
     */
    protected $loop_interval = null;

    /**
     * Control how often the ON_IDLE event fires in applications that do not use a $loop_interval timer.
     *
     * The ON_IDLE event gives your application (and the PHP Simple Daemon library) a way to defer work to be run
     * when your application has idle time and would normally just sleep(). In timer-based applications that is very
     * deterministic. In applications that don't use the $loop_interval timer, this probability factor applied in each
     * iteration of the event loop to periodically dispatch ON_IDLE.
     *
     * Note: This value is completely ignored when using $loop_interval. In those cases, ON_IDLE is fired when there is
     *       remaining time at the end of your loop.
     *
     * Note: If you want to take responsibility for dispatching the ON_IDLE event in your application, just set
     *       this to 0 and dispatch the event periodically, eg:
     *       $this->dispatch(array(self::ON_IDLE));
     *
     * @var float The probability, from 0.0 to 1.0.
     */
    protected $idle_probability = 0.50;

    /**
     * The frequency of your application restarting itself. In seconds.
     *
     * @example $this->auto_restart_interval = 3600;    // Daemon will be restarted once an hour
     * @example $this->auto_restart_interval = 43200;   // Daemon will be restarted twice per day
     * @example $this->auto_restart_interval = 86400;   // Daemon will be restarted once per day
     *
     * @var integer The interval in Seconds
     */
    protected $auto_restart_interval = 43200;

    /**
     * Process ID
     * @var integer
     */
    private $pid;

    /**
     * Array of worker aliases
     * @var Array
     */
    private $workers = array();

    /**
     * Array of plugin aliases
     * @var Array
     */
    private $plugins = array();

    /**
     * Map of callbacks that have been registered using on()
     * @var Array
     */
    private $callbacks = array();

    /**
     * Runtime statistics for a recent window of execution
     * @var Array
     */
    private $stats = array();

    /**
     * Dictionary of application-wide environment vars with defaults.
     * @see Core_Daemon::set()
     * @see Core_Daemon::get()
     * @var array
     */
    private static $env = array(
        'parent' => true,
    );

    /**
     * Handle for log() method,
     * @see Core_Daemon::log()
     * @see Core_Daemon::restart();
     * @var stream
     */
    private static $log_handle = false;

    /**
     * Implement this method to define plugins
     * @return void
     */
    protected function setup_plugins()
    {

    }

    /**
     * Implement this method to define workers
     * @return void
     */
    protected function setup_workers()
    {

    }

    /**
     * The setup method will contain the one-time setup needs of the daemon.
     * It will be called as part of the built-in init() method.
     * Any exceptions thrown from setup() will be logged as Fatal Errors and result in the daemon shutting down.
     * @return void
     * @throws Exception
     */
    abstract protected function setup();

    /**
     * The execute method will contain the actual function of the daemon.
     * It can be called directly if needed but its intention is to be called every iteration by the ->run() method.
     * Any exceptions thrown from execute() will be logged as Fatal Errors and result in the daemon attempting to restart or shut down.
     *
     * @return void
     * @throws Exception
     */
    abstract protected function execute();

    /**
     * Return a log file name that will be used by the log() method.
     *
     * You could hard-code a string like '/var/log/myapplog', read an option from an ini file, create a simple log
     * rotator using the date() method, etc
     *
     * Note: This method will be called during startup and periodically afterwards, on even 5-minute intervals: If you
     *       start your application at 13:01:00, the next check will be at 13:05:00, then 13:10:00, etc. This periodic
     *       polling enables you to build simple log rotation behavior into your app.
     *
     * @return string
     */
    abstract protected function log_file();


    /**
     * Return an instance of the Core_Daemon singleton
     * @return Core_Daemon
     */
    public static function getInstance()
    {
        static $o = null;

        if (!$o)
            try
            {
                $o = new static;
                $o->setup_plugins();
                $o->setup_workers();
                $o->check_environment();
                $o->init();
            }
            catch (Exception $e)
            {
                $o->fatal_error($e->getMessage());
            }

        return $o;
    }

    /**
     * Set an application-wide environment variable
     * @param $key
     * @param $value
     */
    protected static function set($key, $value)
    {
        self::$env[$key] = $value;
    }

    /**
     * Read an application-wide environment variable
     * @param $key
     * @return Mixed -- Returns Null if the $key does not exist
     */
    public static function get($key)
    {
        if (isset(self::$env[$key]))
            return self::$env[$key];

        return null;
    }

    /**
     * A simple alias for get() to create more semantically-correct and self-documenting code.
     * @example Core_Daemon::get('parent') === Core_Daemon::is('parent')
     * @param $key
     * @return Mixed
     */
    public static function is($key)
    {
        return self::get($key);
    }

    protected function __construct()
    {
        global $argv;

        // We have to set any installation instructions before we call getopt()
        $this->install_instructions[] = "Add to Supervisor or Monit, or add a Crontab Entry:\n   * * * * * " . $this->command();
        $this->set('filename',    $argv[0]);
        $this->set('start_time',  time());
        $this->pid(getmypid());
        $this->getopt();
    }

    /**
     * Ensure that essential runtime conditions are met.
     * To easily add rules to this, overload this method, build yourself an array of error messages,
     * and then call parent::check_environment($my_errors)
     * @param Array $errors
     * @return void
     * @throws Exception
     */
    protected function check_environment(Array $errors = array())
    {
        if (is_numeric($this->loop_interval) == false)
            $errors[] = "Invalid Loop Interval: $this->loop_interval";

        if (is_numeric($this->auto_restart_interval) == false)
            $errors[] = "Invalid auto-restart interval: $this->auto_restart_interval";

        if (is_numeric($this->auto_restart_interval) && $this->auto_restart_interval < self::MIN_RESTART_SECONDS)
            $errors[] = 'Auto-restart inteval is too low. Minimum value: ' . self::MIN_RESTART_SECONDS;

        if (function_exists('pcntl_fork') == false)
            $errors[] = "The PCNTL Extension is not installed";

        if (version_compare(PHP_VERSION, '5.3.0') < 0)
            $errors[] = "PHP 5.3 or higher is required";

        foreach ($this->plugins as $plugin)
            foreach ($this->{$plugin}->check_environment() as $error)
                $errors[] = "[$plugin] $error";

        foreach ($this->workers as $worker)
            foreach ($this->{$worker}->check_environment() as $error)
                $errors[] = "[$worker] $error";

        if (count($errors)) {
            $errors = implode("\n  ", $errors);
            throw new Exception("Checking Dependencies... Failed:\n  $errors");
        }
    }

    /**
     * Run the setup() methods of installed plugins, installed workers, and the subclass, in that order. And dispatch the ON_INIT event.
     * @return void
     */
    private function init()
    {
        $signals = array (
            // Handled by Core_Daemon:
            SIGTERM, SIGINT, SIGUSR1, SIGHUP, SIGCHLD,

            // Ignored by Core_Daemon -- register callback ON_SIGNAL to listen for them.
            // Some of these are duplicated/aliased, listed here for completeness
            SIGUSR2, SIGCONT, SIGQUIT, SIGILL, SIGTRAP, SIGABRT, SIGIOT, SIGBUS, SIGFPE, SIGSEGV, SIGPIPE, SIGALRM,
            SIGCONT, SIGTSTP, SIGTTIN, SIGTTOU, SIGURG, SIGXCPU, SIGXFSZ, SIGVTALRM, SIGPROF,
            SIGWINCH, SIGIO, SIGSYS, SIGBABY
        );

        if (defined('SIGPOLL'))     $signals[] = SIGPOLL;
        if (defined('SIGPWR'))      $signals[] = SIGPWR;
        if (defined('SIGSTKFLT'))   $signals[] = SIGSTKFLT;

        foreach(array_unique($signals) as $signal)
            pcntl_signal($signal, array($this, 'signal'));

        $this->plugin('ProcessManager');
        foreach ($this->plugins as $plugin)
            $this->{$plugin}->setup();

        $this->dispatch(array(self::ON_INIT));

        foreach ($this->workers as $worker)
            $this->{$worker}->setup();

        $this->loop_interval($this->loop_interval);

        // Queue any housekeeping tasks we want performed periodically
        $this->on(self::ON_IDLE, array($this, 'stats_trim'), (empty($this->loop_interval)) ? null : ($this->loop_interval * 50)); // Throttle to about once every 50 iterations

        $this->setup();
        if (!$this->is('daemonized'))
            $this->log('Note: The daemonize (-d) option was not set: This process is running inside your shell. Auto-Restart feature is disabled.');

        $this->log('Application Startup Complete. Starting Event Loop.');
    }

    /**
     * Tear down all plugins and workers and free any remaining resources
     * @return void
     */
    public function __destruct()
    {
        try
        {
            $this->set('shutdown', true);
            $this->dispatch(array(self::ON_SHUTDOWN));
            foreach(array_merge($this->workers, $this->plugins) as $object) {
                $this->{$object}->teardown();
                unset($this->{$object});
            }
        }
        catch (Exception $e)
        {
            $this->fatal_error(sprintf('Exception Thrown in Shutdown: %s [file] %s [line] %s%s%s',
                $e->getMessage(), $e->getFile(), $e->getLine(), PHP_EOL, $e->getTraceAsString()));
        }

        $this->callbacks = array();

        if ($this->is('parent') && $this->get('pid_file') && file_exists($this->get('pid_file')) && file_get_contents($this->get('pid_file')) == $this->pid)
            unlink($this->get('pid_file'));

        if ($this->is('parent') && $this->is('stdout'))
            echo PHP_EOL;
    }

    /**
     * Some accessors are available as setters only within their defined scope, but can be
     * used as getters universally. Filter out setter arguments and proxy the call to the accessor.
     * Note: A warning will be raised if the accessor is used as a setter out of scope.
     * @example $someDaemon->loop_interval()
     *
     * @param $method
     * @param $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        $deprecated = array('shutdown', 'verbose', 'is_daemon', 'filename');
        if (in_array($method, $deprecated)) {
          throw new Exception("Deprecated method call: $method(). Update your code to use the v2.1 get(), set() and is() methods.");
        }

        $accessors = array('loop_interval', 'pid');
        if (in_array($method, $accessors)) {
            if ($args)
                trigger_error("The '$method' accessor can not be used as a setter in this context. Supplied arguments ignored.", E_USER_WARNING);

            return call_user_func_array(array($this, $method), array());
        }

        // Handle any calls to __invoke()able objects
        if (in_array($method, $this->workers))
            return call_user_func_array($this->$method, $args);

        throw new Exception("Invalid Method Call '$method'");
    }

    /**
     * This is the main program loop for the daemon
     * @return void
     */
    public function run()
    {
        try
        {
            while ($this->is('parent') && !$this->is('shutdown'))
            {
                $this->timer(true);
                $this->auto_restart();
                $this->dispatch(array(self::ON_PREEXECUTE));
                $this->execute();
                $this->dispatch(array(self::ON_POSTEXECUTE));
                $this->timer();
            }
        }
        catch (Exception $e)
        {
            $this->fatal_error(sprintf('Uncaught Exception in Event Loop: %s [file] %s [line] %s%s%s',
                $e->getMessage(), $e->getFile(), $e->getLine(), PHP_EOL, $e->getTraceAsString()));
        }
    }

    /**
     * Register a callback for the given $event. Use the event class constants for built-in events. Add and dispatch
     * your own events however you want.
     * @param $event mixed scalar  When creating custom events, keep ints < 100 reserved for the daemon
     * @param $callback closure|callback
     * @param $throttle Optional time in seconds to throttle calls to the given $callback. For example, if
     *        $throttle = 10, the provided $callback will not be called more than once every 10 seconds, even if the
     *        given $event is dispatched more frequently than that.
     * @param $criteria closure|callback Optional. If provided, any event payload will be passed to this callable and
     *        the event dispatched only if it returns truthy.
     * @return array    The return value can be passed to off() to unbind the event
     * @throws Exception
     */
    public function on($event, $callback, $throttle = null, $criteria = null)
    {
        if (!is_scalar($event))
            throw new Exception(__METHOD__ . ' Failed. Event type must be Scalar. Given: ' . gettype($event));

        if (!isset($this->callbacks[$event]))
            $this->callbacks[$event] = array();

        $this->callbacks[$event][] = array (
            'callback'  => $callback,
            'criteria'  => $criteria,
            'throttle'  => $throttle,
            'call_at'   => 0
        );

        end($this->callbacks[$event]);
        return array($event, key($this->callbacks[$event]));
    }

    /**
     * Remove a callback previously registered with on(). Returns the callback.
     * @param array $event  Should be the array returned when you called on()
     * @return callback|closure|null returns the registered event handler assuming $event is valid
     */
    public function off(Array $event)
    {
        if (isset($event[0]) && isset($event[1])) {
            $cb = $this->callbacks[$event[0]][$event[1]];
            unset($this->callbacks[$event[0]][$event[1]]);
            return $cb;
        }
        return null;
    }

    /**
     * Dispatch callbacks. Can either pass an array referencing a specific callback (eg the return value from an on() call)
     * or you can pass it an array with the event type and all registered callbacks will be called.
     * @param array $event  Either an array with a single item (an event type) or 2
     *                      items (an event type, and a callback ID for that event type)
     * @param array $args   Array of arguments passed to the event listener
     */
    public function dispatch(Array $event, Array $args = array())
    {
        if (!isset($event[0]) || !isset($this->callbacks[$event[0]]))
            return;

        // A specific callback is being dispatched...
        if (isset($event[1]) && isset($this->callbacks[$event[0]][$event[1]])) {
            $callback =& $this->callbacks[$event[0]][$event[1]];

            if ($callback['throttle'] && time() < $callback['call_at'])
                return;

            if (is_callable($callback['criteria']) && !$callback['criteria']($args))
                return;

            $callback['call_at'] = time() + (int)$callback['throttle'];
            call_user_func_array($callback['callback'], $args);
            return;
        }

        // All callbacks attached to a given event are being dispatched...
        foreach($this->callbacks[$event[0]] as $callback_id => $callback) {

            if ($callback['throttle'] && time() < $callback['call_at'])
                continue;

            if (is_callable($callback['criteria']) && !$callback['criteria']($args))
                return;

            $this->callbacks[$event[0]][$callback_id]['call_at'] = time() + (int)$callback['throttle'];
            call_user_func_array($callback['callback'], $args);
        }
    }

    /**
     * Run any task asynchronously by passing it to this method. Will fork into a child process, execute the supplied
     * code, and exit.
     *
     * The $callable provided can be a standard PHP Callback, a Closure, or any object that implements Core_ITask
     *
     * Note: If the task uses MySQL or certain other outside resources, the connection will have to be
     * re-established in the child process. There are three options:
     *
     * 1. Put any mysql setup or connection code in your task:
     *    This is not suggested because it's bad design but it's certainly possible.
     *
     * 2. Run the same setup code in every background task:
     *    Any event handlers you set using $this->on(ON_FORK) will be run in every forked Task and Worker process
     *    before the callable is called.
     *
     * 3. Run setup code specific to the current background task:
     *    If you need to run specific setup code for a task or worker you have to use an object. You can't use the shortened
     *    form of passing a callback or closure. For tasks that means an object that implements Core_ITask. For workers,
     *    it's Core_IWorker. The setup() and teardown() methods defined in the interfaces are natural places to handle
     *    database connections, etc.
     *
     * @link https://github.com/shaneharter/PHP-Daemon/wiki/Tasks
     *
     * @param callable|Core_ITask $callable     A valid PHP callback or closure.
     * @param Mixed                             All additional params are passed to the $callable
     * @return Core_Lib_Process|boolean         Return a newly created Process object or false on failure
     */
    public function task($task)
    {
        if ($this->is('shutdown')) {
            $this->log("Daemon is shutting down: Cannot run task()");
            return false;
        }

        // Standardize the $task into a $callable
        // If a Core_ITask was passed in, wrap it in a closure
        // If no group is provided, add the process to an adhoc "tasks" group. A group identifier is required.
        // @todo this group thing is not elegant. Improve it.
        if ($task instanceof Core_ITask) {
            $group = $task->group();
            $callable = function() use($task) {
                $task->setup();
                call_user_func(array($task, 'start'));
                $task->teardown();
            };
        } else {
            $group = 'tasks';
            $callable = $task;
        }

        $proc = $this->ProcessManager->fork($group);

        if ($proc === false) {
            // Parent Process - Fork Failed
            $e = new Exception();
            $this->error('Task failed: Could not fork.');
            $this->error($e->getTraceAsString());
            return false;
        }

        if ($proc === true) {

            // Child Process
            $this->set('start_time', time());
            $this->set('parent',     false);
            $this->set('parent_pid', $this->pid);
            $this->pid(getmypid());

            // Remove unused worker objects. They can be memory hogs.
            foreach($this->workers as $worker)
                if (!is_array($callable) || $callable[0] != $this->{$worker})
                    unset($this->{$worker});

            $this->workers = $this->stats = array();

            try {
                call_user_func_array($callable, array_slice(func_get_args(), 1));
            } catch (Exception $e) {
                $this->error('Exception Caught in Task: ' . $e->getMessage());
            }

            exit;
        }

        // Parent Process - Return the newly created Core_Lib_Process object
        return $proc;
    }

    /**
     * Log the $message to the filename returned by Core_Daemon::log_file() and/or optionally print to stdout.
     * Multi-Line messages will be handled nicely.
     *
     * Note: Your log_file() method will be called every 5 minutes (at even increments, eg 00:05, 00:10, 00:15, etc) to
     * allow you to rotate the filename based on time (one log file per month, day, hour, whatever) if you wish.
     *
     * Note: You may find value in overloading this method in your app in favor of a more fully-featured logging tool
     * like log4php or Zend_Log. There are fantastic logging libraries available, and this simplistic home-grown option
     * was chosen specifically to avoid forcing another dependency on you.
     *
     * @param string $message
     * @param string $label Truncated at 12 chars
     */
    public function log($message, $label = '', $indent = 0)
    {        
        static $log_file = '';
        static $log_file_check_at = 0;
        static $log_file_error = false;

        $header = "\nDate                  PID   Label         Message\n";
        $date   = date("Y-m-d H:i:s");
        $pid    = str_pad($this->pid, 5, " ", STR_PAD_LEFT);
        $label  = str_pad(substr($label, 0, 12), 13, " ", STR_PAD_RIGHT);
        $prefix = "[$date] $pid $label" . str_repeat("\t", $indent);

        if (time() >= $log_file_check_at && $this->log_file() != $log_file) {
            $log_file = $this->log_file();
            $log_file_check_at = mktime(date('H'), (date('i') - (date('i') % 5)) + 5, null);
            @fclose(self::$log_handle);
            self::$log_handle = $log_file_error = false;
        }

        if (self::$log_handle === false) {
            if (strlen($log_file) > 0 && self::$log_handle = @fopen($log_file, 'a+')) {
                if ($this->is('parent')) {
                    fwrite(self::$log_handle, $header);
                    if ($this->is('stdout'))
                        echo $header;
                }
            } elseif (!$log_file_error) {
                $log_file_error = true;
                trigger_error(__CLASS__ . "Error: Could not write to logfile " . $log_file, E_USER_WARNING);
            }
        }

        $message = $prefix . ' ' . str_replace("\n", "\n$prefix ", trim($message)) . "\n";

        if (self::$log_handle)
            fwrite(self::$log_handle, $message);

        if ($this->is('stdout'))
            echo $message;
    }

    public function debug($message, $label = '')
    {
        if (!$this->is('verbose'))
            return;

        $this->log($message, $label);
    }

    /**
     * Log the provided $message and dispatch an ON_ERROR event.
     *
     * The library has no concept of a runtime error. If your application doesn't attach any ON_ERROR listeners, there
     * is literally no difference between using this and just passing the message to Core_Daemon::log().
     *
     * @param $message
     * @param string $label
     */
    public function error($message, $label = '')
    {
        $this->log($message, $label);
        $this->dispatch(array(self::ON_ERROR), array($message));
    }

    /**
     * Raise a fatal error and kill-off the process. If it's been running for a while, it'll try to restart itself.
     * @param string $message
     * @param string $label
     */
    public function fatal_error($message, $label = '')
    {
        $this->error($message, $label);

        if ($this->is('parent')) {
            $this->log(get_class($this) . ' is Shutting Down...');

            $delay = 2;
            if ($this->is('daemonized') && ($this->runtime() + $delay) > self::MIN_RESTART_SECONDS) {
                sleep($delay);
                $this->restart();
            }
        }

        // If we get here, it means we couldn't try a re-start or we tried and it just didn't work.
        echo PHP_EOL;
        exit(1);
    }

    /**
     * When a signal is sent to the process it'll be handled here
     * @param integer $signal
     * @return void
     */
    public function signal($signal)
    {
        switch ($signal)
        {
            case SIGUSR1:
                // kill -10 [pid]
                $this->dump();
                break;
            case SIGHUP:
                // kill -1 [pid]
                $this->restart();
                break;
            case SIGINT:
            case SIGTERM:
                if ($this->is('parent'))
                    $this->log("Shutdown Signal Received\n");

                $this->set('shutdown', true);
                break;
        }

        $this->dispatch(array(self::ON_SIGNAL), array($signal));
    }

    /**
     * Get the fully qualified command used to start (and restart) the daemon
     * @param string $options    An options string to use in place of whatever options were present when the daemon was started.
     * @return string
     */
    private function command($options = false)
    {
        $command = 'php ' . $this->get('filename');

        if ($options === false) {
            $command .= ' -d';
            if ($this->get('pid_file'))
                $command .= ' -p ' . $this->get('pid_file');

            if ($this->get('debug_workers'))
                $command .= ' --debugworkers';
        }
        else {
            $command .= ' ' . trim($options);
        }

        // We have to explicitly redirect output to /dev/null to prevent the exec() call from hanging
        $command .= ' > /dev/null';

        return $command;
    }

    /**
     * This will dump various runtime details to the log.
     * @example $ kill -10 [pid]
     * @return void
     */
    private function dump()
    {
        $workers = '';
        foreach($this->workers as $worker)
            $workers .= sprintf('%s %s [%s], ', $worker, $this->{$worker}->guid, $this->{$worker}->is_idle() ? 'AVAILABLE' : 'BUFFERING');

        $pretty_memory = function($bytes) {
            $kb = 1024; $mb = $kb * 1024; $gb = $mb * 1024;
            switch(true) {
                case $bytes > $gb: return sprintf('%sG', number_format($bytes / $gb, 2));
                case $bytes > $mb: return sprintf('%sM', number_format($bytes / $mb, 2));
                case $bytes > $kb: return sprintf('%sK', number_format($bytes / $kb, 2));
                default: return $bytes;
            }
        };

        $pretty_duration = function($seconds) {
            $m = 60; $h = $m * 60; $d = $h * 24;
            $out = '';
            switch(true) {
                case $seconds > $d:
                    $out .= intval($seconds / $d) . 'd ';
                    $seconds %= $d;
                case $seconds > $h:
                    $out .= intval($seconds / $h) . 'h ';
                    $seconds %= $h;
                case $seconds > $m:
                    $out .= intval($seconds / $m) . 'm ';
                    $seconds %= $m;
                default:
                    $out .= "{$seconds}s";
            }
            return $out;
        };

        $pretty_bool = function($bool) {
            return ($bool ? 'Yes' : 'No');
        };

        $out = array();
        $out[] = "---------------------------------------------------------------------------------------------------";
        $out[] = "Application Runtime Statistics";
        $out[] = "---------------------------------------------------------------------------------------------------";
        $out[] = "Command:              " . ($this->is('parent') ? $this->get('filename') : 'Forked Process from pid ' . $this->get('parent_pid'));
        $out[] = "Loop Interval:        " . $this->loop_interval;
        $out[] = "Idle Probability      " . $this->idle_probability;
        $out[] = "Restart Interval:     " . $this->auto_restart_interval;
        $out[] = sprintf("Start Time:           %s (%s)", $this->get('start_time'), date('Y-m-d H:i:s', $this->get('start_time')));
        $out[] = sprintf("Duration:             %s (%s)", $this->runtime(), $pretty_duration($this->runtime()));
        $out[] = "Log File:             " . $this->log_file();
        $out[] = "Daemon Mode:          " . $pretty_bool($this->is('daemonized'));
        $out[] = "Shutdown Signal:      " . $pretty_bool($this->is('shutdown'));
        $out[] = "Process Type:         " . ($this->is('parent') ? 'Application Process' : 'Background Process');
        $out[] = "Plugins:              " . implode(', ', $this->plugins);
        $out[] = "Workers:              " . $workers;
        $out[] = sprintf("Memory:               %s (%s)", memory_get_usage(true), $pretty_memory(memory_get_usage(true)));
        $out[] = sprintf("Peak Memory:          %s (%s)", memory_get_peak_usage(true), $pretty_memory(memory_get_peak_usage(true)));
        $out[] = "Current User:         " . get_current_user();
        $out[] = "Priority:             " . pcntl_getpriority();
        $out[] = "Loop: duration, idle: " . implode(', ', $this->stats_mean()) . ' (Mean Seconds)';
        $out[] = "Stats sample size:    " . count($this->stats);
        $this->log(implode("\n", $out));
    }

    /**
     * Time the execution loop and sleep an appropriate amount of time.
     * @param boolean $start
     * @return mixed
     */
    private function timer($start = false)
    {
        static $start_time = null;

        // Start the Stop Watch and Return
        if ($start)
            return $start_time = microtime(true);

        // End the Stop Watch

        // Determine if we should run the ON_IDLE tasks.
        // In timer based applications, determine if we have remaining time.
        // Otherwise apply the $idle_probability factor

        $end_time = $probability = null;

        if ($this->loop_interval)    $end_time = ($start_time + $this->loop_interval() - 0.01);
        if ($this->idle_probability) $probability = (1 / $this->idle_probability);

        $is_idle = function() use($end_time, $probability) {
            if ($end_time)
                return microtime(true) < $end_time;

            if ($probability)
                return mt_rand(1, $probability) == 1;

            return false;
        };

        // If we have idle time, do any housekeeping tasks
        if ($is_idle()) {
            $this->dispatch(array(Core_Daemon::ON_IDLE), array($is_idle));
        }

        $stats = array();
        $stats['duration']  = microtime(true) - $start_time;
        $stats['idle']      = $this->loop_interval - $stats['duration'];

        // Suppress child signals during sleep to stop exiting forks/workers from interrupting the timer.
        // Note: SIGCONT (-18) signals are not suppressed and can be used to "wake up" the daemon.
        if ($stats['idle'] > 0) {
            pcntl_sigprocmask(SIG_BLOCK, array(SIGCHLD));
            usleep($stats['idle'] * 1000000);
            pcntl_sigprocmask(SIG_UNBLOCK, array(SIGCHLD));
        } else {
            // There is no time to sleep between intervals -- but we still need to give the CPU a break
            // Sleep for 1/100 a second.
            usleep(10000);
            if ($this->loop_interval > 0)
                $this->error('Run Loop Taking Too Long. Duration: ' . number_format($stats['duration'], 3) . ' Interval: ' . $this->loop_interval);
        }

        $this->stats[] = $stats;
        return $stats;
    }

    /**
     * If this is in daemon mode, provide an auto-restart feature.
     * This is designed to allow us to get a fresh stack, fresh memory allocation, etc.
     * @return boolean|void
     */
    private function auto_restart()
    {
        if (!$this->is('parent') || !$this->is('daemonized'))
            return;

        if ($this->runtime() < $this->auto_restart_interval || $this->auto_restart_interval < self::MIN_RESTART_SECONDS)
            return false;

        $this->restart();
    }

    /**
     * There are 2 paths to the daemon calling restart: The Auto Restart feature, and, also, if a fatal error
     * is encountered after it's been running for a while, it will attempt to re-start.
     * @return void;
     */
    public function restart()
    {
        if (!$this->is('parent') || !$this->is('daemonized'))
            return;

        $this->set('shutdown', true);
        $this->log('Restart Happening Now...');

        // We want to shutdown workers, release any lock files, and swap out the pid file (as applicable)
        // Basically put this into a walking-dead state by destructing everything while keeping this process alive
        // to actually orchestrate the restart.
        $this->__destruct();

        // Close the resource handles to prevent this process from hanging on the exec() output.
        if (is_resource(STDOUT)) fclose(STDOUT);
        if (is_resource(STDERR)) fclose(STDERR);
        if (is_resource(STDIN))  fclose(STDIN);
        // Close the static log handle to prevent it being inherrited by the new process.
        if (is_resource(self::$log_handle)) fclose(self::$log_handle);
        exec($this->command());

        // A new daemon process has been created. This one will stick around just long enough to clean up the worker processes.
        exit();
    }

    /**
     * Load any plugin that implements the Core_IPlugin.
     *
     * This is an object loader. What we care about is what object to create and where to put it. What we care about first
     * is an alias. This will be the name of the instance variable where the object will be set. If the alias also matches the name
     * of a class in Core/Plugin then it will just magically instantiate that class and be on its way. The following
     * two examples are identical:
     *
     * @example $this->plugin('ini');
     * @example $this->plugin('ini', new Core_Plugin_Ini() );
     *
     * In both of the preceding examples, a Core_Plugin_Ini object is available throughout your application object
     * as $this->ini.
     *
     * More complex (or just less magical) code can be used when appropriate. Want to load multiple instances of a plugin?
     * Want to use more meaningful names in your application instead of just duplicating part of the class name?
     * You can do all that too. This is simple dependency injection. Inject whatever object you want at runtime as long
     * as it implements Core_IPlugin.
     *
     * @example $this->plugin('credentials', new Core_Plugins_Ini());
     *          $this->plugin('settings', new Core_Plugins_Ini());
     *          $this->credentials->filename = '~/prod/credentials.ini';
     *          $this->settings->filename = BASE_PATH . '/MyDaemon/settings.ini';
     *          echo $this->credentials['mysql']['user']; // Echo the 'user' key in the 'mysql' section
     *
     * Note: As demonstrated, the alias is used simply as the name of a public instance variable on your application
     * object. All of the normal rules of reality apply: Aliases must be unique across plugins AND workers (which work
     * exactly like plugins in this respect). And both must be unique from any other instance or class vars used in
     * Core_Daemon or in your application superclass.
     *
     * Note: The Lock objects in Core/Lock are also Plugins and can be loaded in nearly the same way.
     * Take Core_Lock_File for instance.  The only difference is that you cannot magically load it using the alias
     * 'file' alone. The Plugin loader would not know to look for the file in the Lock directory. In these instances
     * the prefix is necessary.
     * @example $this->plugin('Lock_File'); // Instantiated at $this->Lock_File
     *
     * @param string $alias
     * @param Core_IPlugin|null $instance
     * @return Core_IPlugin Returns an instance of the plugin
     * @throws Exception
     */
    protected function plugin($alias, Core_IPlugin $instance = null)
    {
        $this->check_alias($alias);

        if ($instance === null) {
            // This if wouldn't be necessary if /Lock lived inside /Plugin.
            // Now that Locks are plugins in every other way, maybe it should be moved. OTOH, do we really need 4
            // levels of directory depth in a project with like 10 files...?
            if (substr(strtolower($alias), 0, 5) == 'lock_')
                $class = 'Core_' . ucfirst($alias);
            else
                $class = 'Core_Plugin_' . ucfirst($alias);

            if (class_exists($class, true)) {
                $interfaces = class_implements($class, true);
                if (is_array($interfaces) && isset($interfaces['Core_IPlugin'])) {
                    $instance = new $class($this->getInstance());
                }
            }
        }

        if (!is_object($instance)) {
            throw new Exception(__METHOD__ . " Failed. Could Not Load Plugin '{$alias}'");
        }

        $this->{$alias} = $instance;
        $this->plugins[] = $alias;
        return $instance;
    }

    /**
     * Create a persistent Worker process. This is an object loader similar to Core_Daemon::plugin().
     *
     * @param String $alias  The name of the worker -- Will be instantiated at $this->{$alias}
     * @param callable|Core_IWorker $worker An object of type Core_Worker OR a callable (function, callback, closure)
     * @param Core_IWorkerVia $via  A Core_IWorkerVia object that defines the medium for IPC (In theory could be any message queue, redis, memcache, etc)
     * @return Core_Worker_ObjectMediator Returns a Core_Worker class that can be used to interact with the Worker
     * @todo Use 'callable' type hinting if/when we move to a php 5.4 requirement.
     */
    protected function worker($alias, $worker, Core_IWorkerVia $via = null)
    {
        if (!$this->is('parent'))
            // While in theory there is nothing preventing you from creating workers in child processes, supporting it
            // would require changing a lot of error handling and process management code and I don't really see the value in it.
            throw new Exception(__METHOD__ . ' Failed. You cannot create workers in a background processes.');

        if ($via === null)
            $via = new Core_Worker_Via_SysV();

        $this->check_alias($alias);

        switch (true) {
            case is_object($worker) && !is_a($worker, 'Closure'):
                $mediator = new Core_Worker_ObjectMediator($alias, $this, $via);

                // Ensure that there are no reserved method names in the worker object -- Determine if there will
                // be a collision between worker methods and public methods on the Mediator class
                // Exclude any methods required by the Core_IWorker interface from the check.
                $intersection = array_intersect(get_class_methods($worker), get_class_methods($mediator));
                $intersection = array_diff($intersection, get_class_methods('Core_IWorker'));
                if (!empty($intersection))
                    throw new Exception(sprintf('%s Failed. Your worker class "%s" contains restricted method names: %s.',
                        __METHOD__, get_class($worker), implode(', ', $intersection)));

                $mediator->setObject($worker);
                break;

            case is_callable($worker):
                $mediator = new Core_Worker_FunctionMediator($alias, $this, $via);
                $mediator->setFunction($worker);
                break;

            default:
                throw new Exception(__METHOD__ . ' Failed. Could Not Load Worker: ' . $alias);
        }

        $this->workers[] = $alias;
        $this->{$alias} = $mediator;
        return $this->{$alias};
    }

    /**
     * Simple function to validate that alises for Plugins or Workers won't interfere with each other or with existing daemon properties.
     * @param $alias
     * @throws Exception
     */
    private function check_alias($alias) {
        if (empty($alias) || !is_scalar($alias))
            throw new Exception("Invalid Alias. Identifiers must be scalar.");

        if (isset($this->{$alias}))
            throw new Exception("Invalid Alias. The identifier `{$alias}` is already in use or is reserved");
    }

    /**
     * Handle command line arguments. To easily extend, just add parent::getopt at the TOP of your overloading method.
     * @return void
     */
    protected function getopt()
    {
        $opts = getopt('hHiI:o:dp:', array('install', 'recoverworkers', 'debugworkers', 'verbose'));

        if (isset($opts['H']) || isset($opts['h']))
            $this->show_help();

        if (isset($opts['i']))
            $this->show_install_instructions();

        if (isset($opts['I']))
            $this->create_init_script($opts['I'], isset($opts['install']));

        if (isset($opts['d'])) {
            if (pcntl_fork() > 0)
                exit();

            $this->pid(getmypid()); // We have a new pid now
        }

        $this->set('daemonized',        isset($opts['d']));
        $this->set('recover_workers',   isset($opts['recoverworkers']));
        $this->set('debug_workers',     isset($opts['debugworkers']));
        $this->set('stdout',            !$this->is('daemonized') && !$this->get('debug_workers'));
        $this->set('verbose',           $this->is('stdout') && isset($opts['verbose']));

        if (isset($opts['p'])) {
            $handle = @fopen($opts['p'], 'w');
            if (!$handle)
                $this->show_help('Unable to write PID to ' . $opts['p']);

            fwrite($handle, $this->pid);
            fclose($handle);

            $this->set('pid_file', $opts['p']);
        }
    }

    /**
     * Print a Help Block and Exit. Optionally Print $msg along with it.
     * @param string $msg
     * @return void
     */
    protected function show_help($msg = '')
    {
        $out = array('');

        if ($msg) {
            $out[] =  '';
            $out[] = 'ERROR:';
            $out[] = ' ' . wordwrap($msg, 72, "\n ");
        }

        echo get_class($this);
        $out[] =  'USAGE:';
        $out[] =  ' $ ' . basename($this->get('filename')) . ' -H | -i | -I TEMPLATE_NAME [--install] | [-d] [-p PID_FILE] [--verbose] [--debugworkers]';
        $out[] =  '';
        $out[] =  'OPTIONS:';
        $out[] =  ' -H Shows this help';
        $out[] =  ' -i Print any daemon install instructions to the screen';
        $out[] =  ' -I Create init/config script';
        $out[] =  '    You must pass in a name of a template from the /Templates directory';
        $out[] =  '    OPTIONS:';
        $out[] =  '     --install';
        $out[] =  '       Install the script to /etc/init.d. Otherwise just output the script to stdout.';
        $out[] =  '';
        $out[] =  ' -d Daemon, detach and run in the background';
        $out[] =  ' -p PID_FILE File to write process ID out to';
        $out[] =  '';
        $out[] =  ' --verbose';
        $out[] =  '   Include debug messages in the application log.';
        $out[] =  '   Note: When run as a daemon (-d) or with a debug shell (--debugworkers), application log messages are written only to the log file.';
        $out[] =  '';
        $out[] =  ' --debugworkers';
        $out[] =  '   Run workers under a debug console. Provides tools to debug the inter-process communication between workers.';
        $out[] =  '   Console will only be displayed if Workers are used in your daemon';
        $out[] =  '';
        $out[] =  '';

        echo implode("\n", $out);
        exit();
    }

    /**
     * Print any install instructions and Exit.
     * Could be anything from copying init.d scripts, setting crontab entries, creating executable or writable directories, etc.
     * Add instructions from your daemon by adding them one by one: $this->install_instructions[] = 'Do foo'
     * @return void
     */
    protected function show_install_instructions()
    {
        echo get_class($this) . " Installation Instructions:\n\n - ";
        echo implode("\n - ", $this->install_instructions);
        echo "\n";
        exit();
    }

    /**
     * Create and output an init script for this daemon to provide start/stop/restart functionality.
     * Uses templates in the /Templates directory to produce scripts for different process managers and linux distros.
     * When you create an init script, you should chmod it to 0755.
     *
     * @param string $template The name of a template from the /Templates directory
     * @param bool $install When true, the script will be created in the init.d directory and final setup instructions will be printed to stdout
     * @return void
     */
    protected function create_init_script($template_name, $install = false)
    {
	    $template = dirname(__FILE__) . '/Templates/' . $template_name;

        if (!file_exists($template))
            $this->show_help("Invalid Template Name '{$template_name}'");

        $daemon = get_class($this);
        $script = sprintf(
            file_get_contents($template),
            $daemon,
            $this->command("-d -p /var/run/{$daemon}.pid")
        );

        if (!$install) {
            echo $script;
            echo "\n\n";
            exit;
        }

        // Print out template-specific setup instructions
        switch($template_name) {
            case 'init_ubuntu':
                $filename = '/etc/init.d/' . $daemon;
                $instructions = "\n - To run on startup on RedHat/CentOS:  sudo chkconfig --add {$filename}";
                break;

            default:
                $instructions = '';
        }

        @file_put_contents($filename, $script);
        @chmod($filename, 0755);
        if (file_exists($filename) == false || is_executable($filename) == false)
            $this->show_help("* Must Be Run as Sudo\n * Could Not Write Config File");

        echo "Init Scripts Created Successfully!";
        echo $instructions;
        echo "\n\n";
        exit();
    }

    /**
     * Return the running time in Seconds
     * @return integer
     */
    public function runtime()
    {
        return time() - $this->get('start_time');
    }

    /**
     * Return a list containing the mean duration and idle time of the daemons event loop, ignoring the longest and shortest 5%
     * Note: Stats data is trimmed periodically and is not likely to have more than 200 rows.
     * @param int $last  Limit the working set to the last n iteration
     * @return Array A list as array(duration, idle) averages.
     */
    public function stats_mean($last = 100)
    {
        if (count($this->stats) < $last) {
            $data = $this->stats;
        } else {
            $data = array_slice($this->stats, -$last);
        }

        $count = count($data);
        $n = ceil($count * 0.05);

        // Sort the $data by duration and remove the top and bottom $n rows
        $duration = array();
        for($i=0; $i<$count; $i++) {
            $duration[$i] = $data[$i]['duration'];
        }
        array_multisort($duration, SORT_ASC, $data);
        $count -= ($n * 2);
        $data = array_slice($data, $n, $count);

        // Now compute the corrected mean
        $list = array(0, 0);
        if ($count) {
            for($i=0; $i<$count; $i++) {
                $list[0] += $data[$i]['duration'];
                $list[1] += $data[$i]['idle'];
            }

            $list[0] /= $count;
            $list[1] /= $count;
        }

        return $list;
    }

    /**
     * A method to periodically trim older items from the stats array
     * @return void
     */
    public function stats_trim() {
        $this->stats = array_slice($this->stats, -100, 100);
    }

    /**
     * Combination getter/setter for the $loop_interval property.
     * @param boolean $set_value
     * @return int|null
     */
    protected function loop_interval($set_value = null)
    {
        if ($set_value === null)
            return $this->loop_interval;

        if (!is_numeric($set_value))
            throw new Exception(__METHOD__ . ' Failed. Could not set loop interval. Number Expected. Given: ' . $set_value);

        $this->loop_interval = $set_value;

        $priority = -1;
        if ($set_value >= 5.0 || $set_value <= 0.0)
          $priority = 0;

        if ($priority == pcntl_getpriority())
            return;

        @pcntl_setpriority($priority);
        if (pcntl_getpriority() == $priority) {
            $this->log('Adjusting Process Priority to ' . $priority);
        } else {
            $this->log(
                "Warning: At configured loop_interval a process priorty of `{$priority}` is suggested but this process does not have setpriority privileges.\n" .
                "         Consider running the daemon with `CAP_SYS_RESOURCE` privileges or set it manually using `sudo renice -n {$priority} -p {$this->pid}`"
            );
        }
    }

    /**
     * Combination getter/setter for the $pid property.
     * @param boolean $set_value
     * @return int
     */
    protected function pid($set_value = null)
    {
        if ($set_value === null)
            return $this->pid;

        if (!is_integer($set_value))
            throw new Exception(__METHOD__ . ' Failed. Could not set pid. Integer Expected. Given: ' . $set_value);

        $this->pid = $set_value;
        if ($this->is('parent'))
            $this->set('parent_pid', $set_value);

        $this->dispatch(array(self::ON_PIDCHANGE), array($set_value));
    }
}