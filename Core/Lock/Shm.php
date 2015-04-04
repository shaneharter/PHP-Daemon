<?php

/**
 * Use IPC Shared Memory. The ID will be the daemon run filename, the key will be "pid", the value will be the pid.
 * @author Shane Harter
 */
class Core_Lock_Shm extends Core_Lock_Lock implements Core_IPlugin
{
    const ADDRESS = 1;

    /**
     * @var Resource
     */
	private $shm = false;

    public function __construct(Core_Daemon $daemon, Array $args = array())
    {
        parent::__construct($daemon, $args);
		$this->pid = getmypid();
	}
	
	public function setup()
	{
        $ftok = ftok(Core_Daemon::get('filename'), 'L');
        $this->shm = shm_attach($ftok, 512, 0666);
	}
	
	public function teardown()
	{
        // Check shm validity before shm_get_var, return directly if NULL or FALSE
        // This may happen when check_environment fail.
        if ($this->shm) {
            $lock = shm_get_var($this->shm, self::ADDRESS);
        } else {
            return;
        }

		// If this PID set this lock, release it
		if ($lock['pid'] == $this->pid) {
			shm_remove($this->shm);
            shm_detach($this->shm);
        }
	}
	
	public function check_environment(Array $errors = array())
	{
		$errors = array();
		return $errors;
	}
	
	public function set()
	{
		$lock = $this->check();
		if ($lock)
			throw new Exception('Core_Lock_Shm::set Failed. Existing Lock Detected from PID ' . $lock['pid']);

		shm_put_var($this->shm, self::ADDRESS, array('pid' => $this->pid, 'time' => time()));
	}
	
	protected function get()
	{
		$lock = array();
        if (shm_has_var($this->shm, self::ADDRESS))
            $lock = shm_get_var($this->shm, self::ADDRESS);
        else
            return false;

		// Ensure we're not seeing our own lock
		if ($lock['pid'] == $this->pid)
			return false;

        // If it's expired...
        if ($lock['time'] + $this->ttl + Core_Lock_Lock::$LOCK_TTL_PADDING_SECONDS >= time())
            return $lock;
		
		return false;
	}
}
