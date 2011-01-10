<?php

/**
 * Wrapper class for Memcached supplying Auto-Retry functionality.
 * @author Shane Harter
 * @final
 */
final class Core_Memcache extends Memcache
{
	/**
	 * How long can we usleep within the function before doing a retry. Longer durations will give
	 * us more time for network/server issues to clear-up but there are many problems that won't be helped. 
	 * @var float
	 */
	private $auto_retry_timeout = 0.25;
	private $auto_retry = false;
	
	/**
	 * Use if you want memcache to auto-retry if a set() call fails. 
	 * The timeout will dicatate how long it will attempt to retry.  
	 * @param float $auto_retry_timeout	The duration in seconds where it'll retry, must be at least 0.10 seconds. 
	 */
	public function auto_retry($auto_retry_timeout)
	{
		if (is_numeric($auto_retry_timeout)) {
			$this->auto_retry_timeout = max(0.10, $auto_retry_timeout);
			$this->auto_retry = true;
			return true;	
		}
			
		return false;
	}
	
	public function set($key, $var, $flag = null, $expire = null)
	{
		if ($this->auto_retry)
			$max_tries = intval($this->auto_retry_timeout / 0.10);
		else
			$max_tries = 1;
		
		for ($i=0; $i<$max_tries; $i++)
		{
			if(parent::set($key, $var, $flag, $expire))
				return true;
				
			usleep(100000);
		}
		
		return false;
	}
	
	public function connect_all(array $connections)
	{
		$connection_count = 0;
		foreach ($connections as $connection)
			if ($this->addServer($connection['host'], $connection['port']) == true)
				$connection_count++;
				
		return (count($connections) == $connection_count);
	}
}