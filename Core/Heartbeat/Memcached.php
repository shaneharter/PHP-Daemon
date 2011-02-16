<?php

class Core_Heartbeat_Memcached implements Core_Heartbeat_HeartbeatInterface, Core_ResourceInterface
{
	private $memcache = false;
	
	public $memcache_servers = array();
	public $pid;
	public $daemon_name;
	
	public function __construct()
	{
		$this->pid = getmypid();
	}
	
	public function setup()
	{
		// Connect to memcache
		$this->memcache = new Core_Memcache();
		$this->memcache->ns($this->daemon_name);
		
		if ($this->memcache->connect_all($this->memcache_servers) === false)
			throw new Exception('Core_Daemon::init failed: Memcache Connection Failed');			
	}
	
	public function teardown()
	{
		$this->memcache->delete(Core_Heartbeat_HeartbeatInterface::HEARTBEAT_UNIQUE_ID);
	}
	
	public function check_environment()
	{
		$errors = array();
		
		if (false == (is_array($this->memcache_servers) && count($this->memcache_servers)))
			$errors[] = 'Memcache Plugin: Memcache Servers Are Not Set';
			
		if (false == class_exists('Core_Memcache'))
			$errors[] = 'Memcache Plugin: Dependant Class "Core_Memcache" Is Not Loaded';
			
		if (false == class_exists('Memcache'))
			$errors[] = 'Memcache Plugin: PHP Memcached Extension Is Not Loaded';

		return $errors;
	}
	
	public function set()
	{
		$heartbeat = $this->listen();
		
		if ($heartbeat)
			throw new Exception('Core_Heartbeat_Memcached::set Failed. Additional Heartbeat Detected. Details: ' . $heartbeat);

		$heartbeat = array();
		$heartbeat['pid'] = $this->pid;
		$heartbeat['timestamp'] = time();
		
		$memcache_key = Core_Heartbeat_HeartbeatInterface::HEARTBEAT_UNIQUE_ID;
		$memcache_timeout = Core_Heartbeat_HeartbeatInterface::HEARTBEAT_TTL_SECONDS;
				
		$this->memcache->set($memcache_key, $heartbeat, false, $memcache_timeout);		
	}
	
	public function listen()
	{
		// Cache the result of a listen() for 1/10 a second
		static $heartbeat 		= false;
		static $heartbeat_time 	= false;
		
		if ($result && (microtime(true) - $heartbeat_time) < 0.10)
			return $heartbeat;
		
		// There is no valid cache, so return
		$heartbeat 		= $this->memcache->get(Core_Heartbeat_HeartbeatInterface::HEARTBEAT_UNIQUE_ID);
		$heartbeat_time = microtime(true);
		
		if (empty($heartbeat))
			return false;
		
		// Ensure we're not hearing our own heartbeat
		if ($heartbeat['pid'] == $this->pid)
			return false;
		
		// If We're here, there's another heatbeat. Return a string with the details. 
		return $heartbeat['pid'] . ':' . $heartbeat['timestamp'];
	}
}