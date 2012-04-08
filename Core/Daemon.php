<?php

declare(ticks = 5) ;

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
     * The config.ini has a setting wherein the daemon can be auto-restarted every X seconds.
     * We don't want to kill the server with process spawning so any number below this constant will be ignored.
     * @var integer
     */
    const MIN_RESTART_SECONDS = 10;

    /**
     * Events can be attached to each state using the on() method
     * @var integer
     */
    const ON_ERROR      = 0;
    const ON_SIGNAL     = 1;
    const ON_INIT       = 2;
    const ON_RUN        = 3;
    const ON_FORK       = 4;
    const ON_PIDCHANGE  = 5;
    const ON_SHUTDOWN   = 10;


    /**
     * A stdClass array of all Workers created by the worker() method
     * @var stdClass
     */
    private $workers;

    /**
     * An array of instructions that's displayed when the -i param is passed into the daemon.
     * Helps sysadmins and users of your daemons get installation correct. Guide them to set
     * correct permissions, supervisor/monit setup, crontab entries, init.d scripts, etc
     * @var Array
     */
    protected $install_instructions = array();

    /**
     * The frequency at which the run() loop will run (and execute() method will called). After execute() is called, any remaining time in that
     * interval will be spent in a sleep state. If there is no remaining time, that will be logged as an error condition.
     * @example $this->loop_interval = 300; // execute() will be called once every 5 minutes
     * @example $this->loop_interval = 0.1; // execute() will be called 10 times every second
     * @example $this->loop_interval = 0;   // execute() will be called immediately -- There will be no sleep.
     * @var float    The interval in Seconds
     */
    protected $loop_interval = null;

    /**
     * The frequency (in seconds) at which the timer will automatically restart the daemon.
     * @example $this->auto_restart_interval = 3600; // Daemon will be restarted once an hour
     * @example $this->auto_restart_interval = 86400; // Daemon will be restarted once an day
     * @var integer        The interval in Seconds
     */
    protected $auto_restart_interval = 86400;

    /**
     * If the process is forked, this will indicate whether we're still in the parent or not.
     * @var boolean
     */
    private $is_parent = true;

    /**
     * Timestamp when was this pid started?
     * @var integer
     */
    private $start_time;

    /**
     * Process ID
     * @var integer
     */
    private $pid;

    /**
     * An optional filename wherein the PID was written upon init.
     * @var string
     */
    private $pid_file = false;

    /**
     * Is this process running as a Daemon? Triggered by the -d param. Has a getter/setter.
     * @var boolean
     */
    private $daemon = false;

    /**
     * Has a shutdown signal been received? If so, it will shut down upon completion of this iteration. Has a getter/setter.
     * @var boolean
     */
    private $shutdown = false;

    /**
     * In verbose mode, every log entry is also dumped to stdout, as long as were not in daemon mode. Has a getter/setter.
     * @var boolean
     */
    private $verbose = false;

    /**
     * A simple stack of plugins that are enabled. Set from load_plugin() method.
     * @var Array
     */
    private $plugins = array();

    /**
     * Hash of callbacks that have been registered using on()
     * @var Array
     */
    private $callbacks = array();

    /**
     * Runtime statistics for a recent window of execution
     * @var Array
     */
    public $stats = array();


    /**
     * This has to be set using the Core_Daemon::setFilename before init.
     * It's used as part of the auto-restart mechanism. Probably a way to figure it out programatically tho.
     * @var string
     */
    private static $filename = false;




    /**
     * Implement this method to load plugins -- including a Lock provider plugin. Called very early in Daemon
     * instantiation, before the setup() code is called.
     * @return void
     */
    protected function load_plugins()
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
     * You could hard-code a string like './log', create a simple log rotator using the date() method, etc, etc
     *
     * Note: For performance, the log file handle is kept open until the daemon shuts down. So this method will only
     * be called once, during daemon setup. If you want to rotate logs every hour, for example, you can either
     * overload the log() method or use the auto_restart_interval to restart the daemon every hour.
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
        if ($o) return $o;

        try
        {
            $o = new static;
            $o->load_plugins();
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
     * Set the current Filename wherein this object is being instantiated and run.
     * @param string $filename the acutal filename, pass in __file__
     * @return void
     */
    public static function setFilename($filename)
    {
        self::$filename = realpath($filename);
    }



    protected function __construct()
    {
        // We have to set any installation instructions before we call getopt()
        $this->install_instructions[] = "Add to Supervisor or Monit, or add a Crontab Entry:\n   * * * * * " . $this->getFilename();

        $this->start_time = time();
        $this->pid(getmypid());
        $this->getopt();

        $this->worker = new stdClass; // @todo yeah, probably not
    }

    /**
     * Ensure that essential runtime conditions are met.
     * To easily add rules to this, overload this method, build yourself an array of error messages,
     * and then call parent::check_environment($my_errors)
     * @return void
     * @throws Exception
     */
    protected function check_environment(Array $errors = array())
    {
        if (empty(self::$filename))
            $errors[] = 'Filename is Missing: setFilename Must Be Called Before an Instance can be Initialized';

        if (is_numeric($this->loop_interval) == false)
            $errors[] = "Invalid Loop Interval: $this->loop_interval";

        if (empty($this->auto_restart_interval) || is_numeric($this->auto_restart_interval) == false)
            $errors[] = "Invalid Auto Restart Interval: $this->auto_restart_interval";

        if (is_numeric($this->auto_restart_interval) && $this->auto_restart_interval < self::MIN_RESTART_SECONDS)
            $errors[] = 'Auto Restart Inteval is Too Low. Minimum Value: ' . self::MIN_RESTART_SECONDS;

        if (function_exists('pcntl_fork') == false)
            $errors[] = "The PCNTL Extension is Not Installed";

        if (version_compare(PHP_VERSION, '5.3.0') < 0)
            $errors[] = "PHP 5.3 or Higher is Required";

        foreach ($this->plugins as $plugin)
            foreach ($this->{$plugin}->check_environment() as $error)
                $errors[] = "[$plugin] $error";

        if (count($errors)) {
            $errors = implode("\n  ", $errors);
            throw new Exception("Core_Daemon::check_environment Found The Following Errors:\n  $errors");
        }
    }

    /**
     * Call Plugin and Daemon setup methods
     * @return void
     */
    private function init()
    {
        $this->loop_interval($this->loop_interval);
        $this->register_signal_handlers();

        foreach ($this->plugins as $plugin)
            $this->{$plugin}->setup();

        // Our current use of the ON_INIT event is in the Lock provider plugins -- so we can prevent a duplicate daemon
        // process from starting-up. In that case, we want to do that check as early as possible. To accomplish that,
        // the plugin setup has to happen first -- to ensure the Lock provider plugins have a chance to load.
        $this->dispatch(array(self::ON_INIT));

        $this->setup();
        if ($this->daemon == false)
            $this->log('Note: Auto-Restart feature is disabled when not run in Daemon mode (using -d).');

        $this->log('Process Initialization Complete. Starting timer at a ' . $this->loop_interval . ' second interval.');
    }

    public function __destruct()
    {
        $this->dispatch(array(self::ON_SHUTDOWN));
        foreach ($this->plugins as $plugin)
            $this->{$plugin}->teardown();

        if (!empty($this->pid_file) && file_exists($this->pid_file) && file_get_contents($this->pid_file) == $this->pid)
            unlink($this->pid_file);
    }

    /**
     * Allow named workers to be accessed as a local property
     * @example $this->WorkerName->timeout = 30;
     * @example $this->WorkerName->execute();
     * @param string $name    The name of the worker to access
     */
    public function __get($name)
    {
        if (isset($this->workers->{$name}) && is_object($this->workers->{$name}) && $this->workers->{$name} instanceof Core_Worker)
            return $this->workers->{$name};
    }

    /**
     * Allow named workers to be accessed as a local method
     * @example $this->WorkerName();
     * @param string $name    The name of the worker to access
     */
    public function __call($name, $args)
    {
        if (isset($this->workers->{$name}) && is_object($this->workers->{$name}) && $this->workers->{$name} instanceof Core_Worker)
            return call_user_func_array(array($this->workers->{$name}, 'execute'), $args);
    }

    /**
     * This is the main program loop for the daemon
     * @return void
     */
    public function run()
    {
        try
        {
            while ($this->shutdown == false && $this->is_parent)
            {
                $this->timer(true);
                $this->auto_restart();
                $this->dispatch(array(self::ON_RUN));
                $this->execute();
                $this->timer();
                pcntl_wait($status, WNOHANG);
            }
        }
        catch (Exception $e)
        {
            $this->fatal_error('Error in Core_Daemon::run(): ' . $e->getMessage());
        }
    }

    /**
     * Register a callback for the given $event. Use the event class constants for built-in events, or add and dispatch your own events however you want.
     * @param $event mixed scalar   Event id's under 100 should be reserved for daemon use. For custom events, use any other scalar.
     * @param $callback closure|callback
     * @return array    The return value can be passed to off() to unbind the event
     * @throws Exception
     */
    public function on($event, $callback)
    {
        if (!is_scalar($event))
            throw new Exception(__METHOD__ . ' Failed. Event type must be Scalar. Given: ' . gettype($event));

        if (!is_callable($callback))
            throw new Exception(__METHOD__ . ' Failed. Second Argument Must be Callable.');

        if (!isset($this->callbacks[$event]))
            $this->callbacks[$event] = array();

        $this->callbacks[$event][] = $callback;
        end($this->callbacks[$event]);
        return array($event, key($this->callbacks[$event]));
    }

    /**
     * Remove a callback previously registered with on(). Returns the callback.
     * @param array $event
     * @return callback|closure|null returns the registered event if $ref is valid
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
     * @param array $event  Either an array with a single item (an event type) or 2 items (an event type, and a callback ID for that event type)
     * @param array $args   Array of arguments passed to the event listener
     */
    protected function dispatch(Array $event, Array $args = array())
    {
        if (isset($event[0]) && isset($event[1]) && isset($this->callbacks[$event[0]][$event[1]]))
            call_user_func_array($this->callbacks[$event[0]][$event[1]], $args);
        elseif (isset($event[0]) && !isset($event[1]) && isset($this->callbacks[$event[0]]))
            foreach($this->callbacks[$event[0]] as $id => $callback)
                call_user_func_array($callback, $args);
    }

    /**
     * Parallelize  any task by passing it as a callback or closure. Will fork into a child process, execute the supplied function, and exit.
     * If the task uses MySQL or certain other outside resources, the connection will have to be re-established in the child process
     * so in those cases, set the run_setup flag.
     *
     * @link https://github.com/shaneharter/PHP-Daemon/wiki/Forking-Example
     *
     * @param callable $callback        A valid PHP callback or closure.
     * @param Array $params             The params that will be passed into the Callback when it's called.
     * @param boolean $run_setup        After the child process is created, it will re-run the setup() method.
     * @return boolean                  Cannot know if the callback worked or not, but returns true if the fork was successful.
     */
    public function fork($callback, array $params = array(), $run_setup = false)
    {
        $this->dispatch(array(self::ON_FORK));
        $pid = pcntl_fork();
        switch ($pid)
        {
            case -1:
                // Parent Process - Fork Failed
                if ($callback instanceof Closure)
                    $msg = 'Fork Request Failed. Uncalled Closure';
                else
                    $msg = 'Fork Request Failed. Uncalled Callback: ' . is_array($callback) ? implode('::', $callback) : $callback;

                $this->log($msg, true);
                return false;
                break;

            case 0:
                // Child Process
                $this->is_parent = false;
                $this->pid(getmypid());

                // Truncate the plugins array, so that way
                // when this fork dies and the __destruct runs, it will only shut down
                // plugins that were added to this forked instance explicitly
                $this->plugins = array();

                if ($run_setup) {
                    $this->log("Running Setup in forked PID " . $this->pid);
                    $this->setup();
                }

                try
                {
                    call_user_func_array($callback, $params);
                }
                catch (Exception $e)
                {
                    $this->log('Exception Caught in Fork: ' . $e->getMessage());
                }

                exit;
                break;

            default:
                // Parent Process
                return true;
                break;
        }
    }


    /**
     * Create a persistent Worker process. Essentially a managed Fork, the daemon can apply
     * timeouts, keep the worker running, restart the worker when it's complete, etc.
     * What you CANNOT do is queue jobs to a worker: It can only do one at a time. Queuing jobs in memory is volatile
     * and a daemon crash or restart would lose them. However, a worker integrates with a Job Queue like Gearman or Beanstalkd
     * very naturally.
     *
     * @param String $name            The name of the worker -- Will be instantiated at $this->{$name}
     * @param Function $function      A valid callback or closure
     * @return Core_Worker            Returns a Core_Worker class that can be used to interact with the Worker
     */
    protected function worker($name, $function)
    {
        // get_called_class is a PHP 5.3 function that uses late static binding to return the
        // name of the superclass this is being run in
        $this->worker->{$name} = new Core_Worker($function, $name, get_called_class());
    }

    /**
     * Log the $message to the $this->log_file and possibly print to stdout.
     * Multi-Line messages will be handled nicely. This code is pretty ugly but it works and it lets us avoid
     * forcing another dependency for logging.
     * @param string $message
     * @param boolean $is_error    When true, an ON_ERROR event will be dispatched.
     */
    public function log($message, $is_error = false)
    {
        static $handle = false;
        static $raise_logfile_error = true;

        try
        {
            $header = "Date                  PID   Message\n";
            $date = date("Y-m-d H:i:s");
            $pid = str_pad($this->pid, 5, " ", STR_PAD_LEFT);
            $prefix = "[$date] $pid";

            if ($handle === false) {
                if (strlen($this->log_file()) > 0)
                    $handle = @fopen($this->log_file(), 'a+');

                if ($handle === false) {
                    // If the log file can't be written-to, dump the errors to stdout with the explanation...
                    if ($raise_logfile_error) {
                        $raise_logfile_error = false;
                        echo $header;
                        $this->log('Unable to write logfile at ' . $this->log_file() . '. Redirecting errors to stdout.');
                    }

                    throw new Exception("$prefix $message");
                }

                fwrite($handle, $header);

                if ($this->verbose)
                    echo $header;
            }

            $message = $prefix . ' ' . str_replace("\n", "\n$prefix ", trim($message)) . "\n";
            fwrite($handle, $message);

            if ($this->verbose)
                echo $message;
        }
        catch (Exception $e)
        {
            echo PHP_EOL . $e->getMessage();
        }

        // Optionally distribute this error message to anybody on the ->email_distribution_list
        if ($is_error)
            $this->dispatch(array(self::ON_ERROR), array($message));
    }

    /**
     * Raise a fatal error and kill-off the process. If it's been running for a while, it'll try to restart itself.
     * @param string $log_message
     */
    public function fatal_error($log_message)
    {
        // Log the Error
        $this->log($log_message, true);
        $this->log(get_class($this) . ' is Shutting Down...');

        $delay = 2;
        if (($this->runtime() + $delay) > self::MIN_RESTART_SECONDS) {
            sleep($delay);
            $this->restart();
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
        $this->dispatch(array(self::ON_SIGNAL), array($signal));
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
                $this->log("Shutdown Signal Received\n");
                $this->shutdown = true;
                break;
            default:
                // handle all other signals
        }
    }

    /**
     * Register Signal Handlers
     * Note: SIGKILL is missing -- afaik this is uncapturable in a PHP script, which makes sense.
     * Note: Some of these signals have special meaning and use in POSIX systems like Linux. Use with care.
     * @return void
     */
    private function register_signal_handlers()
    {
        $signals = array(
            // Handled by Core_Daemon:
            SIGTERM, SIGINT, SIGUSR1, SIGHUP,

            // Ignored by Core_Daemon -- register callback ON_SIGNAL to listen for them.
            // Some of these are duplicated/aliased, listed here for completeness
            SIGUSR2, SIGCONT, SIGQUIT, SIGILL, SIGTRAP, SIGABRT, SIGIOT, SIGBUS, SIGFPE, SIGSEGV, SIGPIPE, SIGALRM,
            SIGCHLD, SIGCONT, SIGTSTP, SIGTTIN, SIGTTOU, SIGURG, SIGXCPU, SIGXFSZ, SIGVTALRM, SIGPROF,
            SIGWINCH, SIGPOLL, SIGIO, SIGPWR, SIGSYS, SIGBABY, SIGSTKFLT, SIGCLD
        );

        foreach(array_unique($signals) as $signal) {
            pcntl_signal($signal, array($this, 'signal'));
        }
    }

    /**
     * Get the fully qualified command used to start (and restart) the daemon
     * @param string $options    An options string to use in place of whatever options were present when the daemon was started.
     * @return string
     */
    private function getFilename($options = false)
    {
        $command = 'php ' . self::$filename;

        if ($options === false) {
            $command .= ' -d';
            if ($this->pid_file)
                $command .= ' -p ' . $this->pid_file;
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
        $out = array();
        $out[] = "Dump Signal Recieved";
        $out[] = "Loop Interval: " . $this->loop_interval;
        $out[] = "Restart Interval: " . $this->auto_restart_interval;
        $out[] = "Start Time: " . $this->start_time;
        $out[] = "Duration: " . $this->runtime();
        $out[] = "Log File: " . $this->log_file();
        $out[] = "Daemon Mode: " . (int)$this->daemon();
        $out[] = "Shutdown Signal: " . (int)$this->shutdown();
        $out[] = "Verbose Mode: " . (int)$this->verbose();
        $out[] = "Loaded Plugins: " . implode(', ', $this->plugins);
        $out[] = "Named Workers: " . implode(', ', array_keys((array)$this->workers));
        $out[] = "Memory Usage: " . memory_get_usage(true);
        $out[] = "Memory Peak Usage: " . memory_get_peak_usage(true);
        $out[] = "Current User: " . get_current_user();
        $out[] = "Priority: " . pcntl_getpriority();
        $out[] = "Stats (mean): " . implode(', ', $this->stats_mean());
        $this->log(implode("\n", $out));
    }

    /**
     * Time the execution loop and sleep an appropriate amount of time.
     * @param boolean $start
     * @return mixed
     */
    private function timer($start = false)
    {
        static $start_time = false;

        // Start the Stop Watch and Return
        if ($start)
            return $start_time = microtime(true);

        // End the Stop Watch
        $stats = array();
        $stats['duration']  = microtime(true) - $start_time;
        $stats['idle']      = $this->loop_interval - $stats['duration'];

        if ($stats['idle'] > 0) {
            // usleep accepts microseconds, 1 second in microseconds = 1,000,000
            usleep($stats['idle'] * 1000000);
        } else {
            // There is no time to sleep between intervals -- but we still need to give the CPU a break
            // Sleep for 1/500 a second.
            usleep(2000);
            if ($this->loop_interval > 0)
                $this->log('Run Loop Taking Too Long. Duration: ' . $stats['duration'] . ' Interval: ' . $this->loop_interval, true);
        }

        // Need to keep stats array from getting too large. Trim it back about once every 100 iterations
        if (mt_rand(1,100) == 50 ) {
            $this->stats = array_slice($this->stats, -100, 100);
        }

        $this->stats[] = $stats;
        return $stats;
    }

    /**`
     * If this is in daemon mode, provide an auto-restart feature.
     * This is designed to allow us to get a fresh stack, fresh memory allocation, etc.
     * @return boolean
     */
    private function auto_restart()
    {
        if ($this->daemon == false)
            return false;

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
        if ($this->is_parent == false)
            return;

        $this->log('Restart Happening Now...');
        foreach($this->plugins as $plugin)
            $this->{$plugin}->teardown();

        // Close the resource handles to prevent this process from hanging on the exec() output.
        if (is_resource(STDOUT)) fclose(STDOUT);
        if (is_resource(STDERR)) fclose(STDERR);
        exec($this->getFilename());
        exit();
    }

    /**
     * Load any plugin that implements the Core_PluginInterface.
     * All Plugin classes must be named Core_ClassNameHere. To select and use a plugin, just provide any path and
     * classname using Zend Framework naming conventions. A reference to this daemon instance will be
     * passed to the plugin constructor. If an alias is given, it will be used to instantiate the class as
     * $this->{$alias} = $plugin. If no alias is given, it uses the value passed in as $class, eg $this->{$class}
     *
     * The plugin loader will always look in the Core/Plugin directory for classes. All 3 of these load the same class:
     * @example plugin('SomeClass')
     * @example plugin('Plugin_SomeClass')
     * @example plugin('Core_Plugin_SomeClass')
     *
     * @example plugin('Lock_File')
     * @example plugin('MyDaemon_Plugin_SomeClass', array(), 'SomeClass')
     *
     * @param string $class
     * @param array $args   Optional array of arguments passed to the Plugin constructor
     * @param bool $alias   Optional alias you can give to the plugin.
     * @return mixed
     * @throws Exception
     */
    protected function plugin($class, Array $args = array(), $alias = false)
    {
        $prefix = '';
        $qualified_class = $class;
        foreach(array('Core', 'Plugin') as $part) {
            $qualified_class = $prefix . $qualified_class;
            if (class_exists($qualified_class, true))
                break;

            $prefix .= "{$part}_";
        }

        if (class_exists($qualified_class, true)) {
            $interfaces = class_implements($qualified_class, true);
            if (is_array($interfaces) && isset($interfaces['Core_PluginInterface'])) {
                if (!empty($alias) && is_scalar($alias)) {
                    $this->{$alias} = new $qualified_class($this->getInstance(), $args);
                    $this->plugins[] = $alias;
                } else {
                    $this->{$class} = new $qualified_class($this->getInstance(), $args);
                    $this->plugins[] = $class;
                }
                return;
            }
        }
        throw new Exception(__METHOD__ . ' Failed. Could Not Load Plugin: ' . $class);
    }

    /**
     * Handle command line arguments. To easily extend, just add parent::getopt at the TOP of your overloading method.
     * @return void
     */
    protected function getopt()
    {
        $opts = getopt('HiI:o:dvp:', array('install'));

        if (isset($opts['H']))
            $this->show_help();

        if (isset($opts['i']))
            $this->show_install_instructions();

        if (isset($opts['I']))
            $this->create_init_script($opts['I'], isset($opts['install']));

        if (isset($opts['d'])) {
            $pid = pcntl_fork();
            if ($pid > 0)
                exit();

            $this->daemon = true;
            $this->pid(getmypid()); // We have a new pid now
        }

        if (isset($opts['v']) && $this->daemon == false)
            $this->verbose = true;

        if (isset($opts['p'])) {
            $handle = @fopen($opts['p'], 'w');
            if (!$handle)
                $this->show_help('Unable to write PID to ' . $opts['p']);

            fwrite($handle, $this->pid);
            fclose($handle);

            $this->pid_file = $opts['p'];
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
        $out[] =  ' # ' . basename(self::$filename) . ' -H | -i | -I TEMPLATE_NAME | [-d] [-v] [-p PID_FILE]';
        $out[] =  '';
        $out[] =  'OPTIONS:';
        $out[] =  ' -H Shows this help';
        $out[] =  '';
        $out[] =  ' -i Print any daemon install instructions to the screen';
        $out[] =  '';
        $out[] =  ' -I Create init/config script';
        $out[] =  '    You must pass in a name of a template in the /Templates directory';
        $out[] =  '    OPTIONS:';
        $out[] =  '     --install Install the script to /etc/init.d. Otherwise just output the script to stdout.';
        $out[] =  '';
        $out[] =  ' -d Daemon, detach and run in the background';
        $out[] =  ' -v Verbose, echo any logged messages. Ignored in Daemon mode.';
        $out[] =  ' -p PID_FILE File to write process ID out to';
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
        $template = dirname($this->filename()) . '/Core/Templates/' . $template_name;

        if (!file_exists($template))
            $this->show_help("Invalid Template Name '{$template_name}'");

        $daemon = get_class($this);
        $script = sprintf(
            file_get_contents($template),
            $daemon,
            $this->getFilename("-d -p /var/run/{$daemon}.pid")
        );

        if (!$install) {
            echo $script;
            echo "\n\n";
            exit;
        }

        $filename = '/etc/init.d/' . $daemon;
        @file_put_contents($filename, $script);
        @chmod($filename, 0755);

        if (file_exists($filename) == false || is_executable($filename) == false)
            $this->show_help("* Must Be Run as Sudo\n * Could Not Write to init.d Directory");

        echo "Init Scripts Created Successfully!";

        // Print out template-specific setup instructions
        switch($template_name) {
            case 'init_ubuntu':
                echo "\n - To run on startup on RedHat/CentOS:  sudo chkconfig --add {$filename}";
                break;
        }
        echo "\n\n";
        exit();
    }

    /**
     * Return the running time in Seconds
     * @return integer
     */
    public function runtime()
    {
        return time() - $this->start_time;
    }

    /**
     * Return the daemon's filename
     * @return integer
     */
    public function filename()
    {
        return self::$filename;
    }

    /**
     * Is this run as a daemon or within a shell?
     * @param boolean $set_value
     */
    public function is_daemon()
    {
        return $this->daemon;
    }

    /**
     * Return a tuple containing the mean duration and idle time of the daemons event loop, ignoring the longest and shortest 5%
     * Note: Stats data is trimmed periodically and is not likely to have more than 200 rows.
     * @param int $last  Limit the working set to the last n iteration
     * @return Array A tuple as array(duration,idle) averages.
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

        // Sort the $data by duration asc and remove the top and bottom $n rows
        $duration = array();
        for($i=0; $i<$count; $i++) {
            $duration[$i] = $data[$i]['duration'];
        }
        array_multisort($duration, SORT_ASC, $data);

        $count -= ($n * 2);
        $data = array_slice($data, $n, $count);

        // Now compute the corrected mean
        $tuple = array(0,0);
        for($i=0; $i<$count; $i++) {
            $tuple[0] += $data[$i]['duration'];
            $tuple[1] += $data[$i]['idle'];
        }

        $tuple[0] /= $count;
        $tuple[1] /= $count;
        return $tuple;
    }

    /**
     * Combination getter/setter for the $is_parent property. Can be called manually inside a forked process.
     * Used automatically when creating named workers.
     * @param boolean $set_value
     */
    protected function is_parent($set_value = null)
    {
        if (is_bool($set_value))
            $this->is_parent = $set_value;

        return $this->is_parent;
    }

    /**
     * Combination getter/setter for the $shutdown property.
     * @param boolean $set_value
     */
    protected function shutdown($set_value = null)
    {
        if (is_bool($set_value))
            $this->shutdown = $set_value;

        return $this->shutdown;
    }

    /**
     * Combination getter/setter for the $verbose property.
     * @param boolean $set_value
     */
    protected function verbose($set_value = null)
    {
        if (is_bool($set_value))
            $this->verbose = $set_value;

        return $this->verbose;
    }

    /**
     * Combination getter/setter for the $loop_interval property.
     * @param boolean $set_value
     */
    protected function loop_interval($set_value = null)
    {
        if ($set_value !== null) {
            if (is_numeric($set_value)) {
                $this->loop_interval = $set_value;
                switch(true) {
                    case $set_value >= 5.0 || $set_value <= 0.0:
                        $priority = 0; break;
                    case $set_value > 2.0:
                        $priority = -1; break;
                    case $set_value > 1.0:
                        $priority = -2; break;
                    case $set_value > 0.5:
                        $priority = -3; break;
                    case $set_value > 0.1:
                        $priority = -4; break;
                    default:
                        $priority = -5;
                }

                if ($priority <> pcntl_getpriority()) {
                    pcntl_setpriority($priority);
                    if (pcntl_getpriority() == $priority) {
                        $this->log('Adjusting Process Priority to ' . $priority);
                    } else {
                        $this->log(
                            "Warning: At configured loop_interval a process priorty of `{$priority}` is suggested but this process does not have setpriority privileges." . PHP_EOL .
                            "         Consider running the daemon with `CAP_SYS_RESOURCE` privileges or set it manually using: sudo renice -n {$priority} -p {$this->pid}"
                        );
                    }
                }

            } else {
                throw new Exception(__METHOD__ . ' Failed. Could not set loop interval. Number Expected. Given: ' . $set_value);
            }
        }

        return $this->loop_interval;
    }

    /**
     * Combination getter/setter for the $pid property.
     * @param boolean $set_value
     */
    protected function pid($set_value = null)
    {
        if ($set_value !== null) {
            if (is_integer($set_value))
                $this->pid = $set_value;
            else
                throw new Exception(__METHOD__ . ' Failed. Could not set pid. Integer Expected. Given: ' . $set_value);

            $this->dispatch(array(self::ON_PIDCHANGE), array($set_value));
        }

        return $this->pid;
    }
}