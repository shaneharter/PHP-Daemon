<?php

/**
 * This is the Lock Provider that isn't
 * It doesn't actually do anything. It's great for development or for running any daemons where you 
 * need or are indifferent to multiple running instances
 *  
 * @author Shane Harter
 * @since 2011-07-28
 */
class Core_Lock_Null implements Core_Lock_LockInterface, Core_ResourceInterface
{
	public $pid;
	public $daemon_name;
	
	public function __construct()
	{
		$this->pid = getmypid();
	}
	
	public function setup()
	{
		// Nothing to setup
	}
	
	public function teardown()
	{
		// Nothing to teardown
	}
	
	public function check_environment()
	{
		// Nothing to check
		return array();
	}
	
	public function set()
	{
		// Nothing to set
	}
	
	public function check()
	{
		// False is a good thing -- it means no heartbeat was detected. 
		return false;
	}
}