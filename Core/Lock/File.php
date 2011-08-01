<?php

/**
 * Use a simple filesystem lock.
 * 
 * @author Shane Harter
 * @since 2011-07-29
 */
class Core_Lock_File extends Core_Lock_Lock implements Core_PluginInterface
{
	/**
	 * The directory where the lockfile will be written. The filename will be whatever you set the $daemon_name to be. 
	 * To use the current directory, define and use a BASE_PATH constant: Using ./ will fail when the script is 
	 * run from crontab.   
	 * 
	 * @var string	A filesystem path 
	 */
	public $path = '';
	
	public method setup()
	{
		// Satisfy Core_PluginInterface
	}

	private function filename()
	{
		return $this->path . $this->daemon_name;
	}
	
	public function teardown()
	{
		@unlink($this->filename());
	}
	
	public function check_environment()
	{
		$errors = array();
		
		if (is_writable($this->filename()) == false)
			$errors[] = 'Lock File "' . $this->filename() . '" Not Writable.';
			
		return $errors;
	}
	
	public function set()
	{
		$lock = $this->check();
		
		if ($lock)
			throw new Exception('Core_Lock_File::set Failed. Additional Lock Detected. PID: ' . $lock);

		// The lock value will contain the procss PID
		file_put_contents($this->filename(), $this->pid);
		
		touch($this->filename());		
	}
	
	protected function get()
	{
		if (file_exists($this->filename()) == false)
			return false;
			
		// This is called by check() which caches the result for a small time, so we shouldn't have to worry about thrashing the disk
		clearstatcache();
			
		// If the lock was set more than N seconds ago, it's expired...
		if (filemtime($this->filename()) < (time() - Core_Lock_Lock::LOCK_TTL_PADDING_SECONDS - $this->ttl))
			return false;
			
		// If the lock isn't expired yet, read its contents -- which will be the PID that wrote it. 
		$lock = file_get_contents($this->filename());
		
		// If we're seeing our own lock..
		if ($lock == $this->pid)
			return false;
		
		// Still here? There's another lock... 
		return $lock;
	}
}