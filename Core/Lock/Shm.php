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

	public function __construct()
	{
		$this->pid = getmypid();
	}
	
	public function setup()
	{
        $ftok = ftok(Core_Daemon::get('filename'), 'L');
        $this->shm = shm_attach($ftok, 512, 0666);
	}
	
	public function teardown()
	{
		// If this PID set this lock, release it
		if ($this->get() == $this->pid) {
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
		shm_put_var($this->shm, self::ADDRESS, $this->pid);
	}
	
	protected function get()
	{
        if (!shm_has_var($this->shm))
            return false;
        
		$lock = shm_get_var($this->shm, self::ADDRESS);
		
		return $lock;
	}
}