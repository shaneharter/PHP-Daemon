<?php

abstract class Core_Lock_Lock implements Core_PluginInterface
{
	public static $LOCK_TTL_PADDING_SECONDS = 2.0;
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
	 * This is added to the const LOCK_TTL_SECONDS to determine how long the lock should last -- any lock provider should be 
	 * self-expiring using these TTL's. If a lock doesn't self expire you're just asking for crash to leave an errant lock behind that has to be 
	 * manually cleared.
	 *   
	 * @var decimal 	Number of seconds the lock should be active -- padded with Core_Lock_Lock::LOCK_TTL_PADDING_SECONDS
	 */
	public $ttl = 0;
	
	public function __construct()
	{
		$this->pid = getmypid();
	}	
	
	abstract public function set();
	abstract protected function get();
	
	/**
	 * Check for the existence of a lock. 
	 * Cache results of get() check for 1/10 a second.
	 *  
	 * @return false OR the PID of a conflicting lock
	 */
	public function check()
	{
		static $get = false;
		static $get_time = false;

		//if ($get_time && (microtime(true) - $get_time) < 0.10)
		//	return $get;
			
		$get = $this->get();
		$get_time = microtime(true);
		
		return $get;
	}
}