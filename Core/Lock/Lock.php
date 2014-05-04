<?php

/**
 * Lock provider base class
 *
 * @todo Create Redis lock provider
 * @todo Create APC lock provider
 */
abstract class Core_Lock_Lock implements Core_IPlugin
{
    public static $LOCK_UNIQUE_ID = 'daemon_lock';

    /**
     * The pid of the current daemon -- Set automatically by the constructor.
     * Also set manually in Core_Daemon::getopt() after the daemon process is forked when run in daemon mode
     * @var integer
     */
    public $pid;

    /**
     * The name of the current domain -- set when the lock provider is instantiated.
     * @var string
     */
    public $daemon_name;

    /**
     * The array of args passed-in at instantiation
     * @var Array
     */
    protected $args = array();

    public function __construct(Core_Daemon $daemon, array $args = array())
    {
        $this->pid = getmypid();
        $this->daemon_name = get_class($daemon);
        $this->args = $args;

        $daemon->on(Core_Daemon::ON_INIT, array($this, 'run'));

        $that = $this;
        $daemon->on(Core_Daemon::ON_PIDCHANGE, function ($args) use ($that) {
            if (!empty($args[0]))
                $that->pid = $args[0];
        });
    }

    /**
     * Write the lock to the shared medium.
     * @abstract
     * @return void
     */
    abstract protected function set();

    /**
     * Read the lock from whatever shared medium it's written to.
     * Should return false if the lock was set by the current process (use $this->pid).
     * Should return false if the process that wrote the lock is no longer running.
     * Should return false if the lock has exceeded it's TTL+LOCK_TTL_PADDING_SECONDS
     * If a lock is valid, it should return the PID that set it.
     * @abstract
     * @return int|falsey
     */
    abstract protected function get();

    /**
     * Check for the existence of a lock.
     *
     * @return bool|int Either false or the PID of the process that has set the lock
     */
    protected function check()
    {
        $pid = $this->get();

        // pid should be a positive number
        if (!$pid)
            return false;
        
        // If we're seeing our own lock..
        if ($pid == $this->pid)
            return false;

        // If the process that wrote the lock is no longer running
        $cmd_output = `ps -p $pid`;
        if (strpos($cmd_output, $pid) === false)
            return false;
        
        return $pid;
    }
    
    /**
     * Implements main plugin logic - die if lock exists or create it otherwise.
     *
     * @return null
     */
    public function run()
    {
        $lock = $this->check();
        if ($lock)
            throw new Exception(get_class($this) . '::' . __FUNCTION__ . ' failed. Existing lock detected from PID: ' . $lock);
        
        $this->set();
    }
}