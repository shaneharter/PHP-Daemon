<?php

/**
 * This is the Lock Provider that isn't.
 * It doesn't actually do anything. It's great for development or for running any daemons
 * where you are indifferent to multiple running instances
 *  
 * @author Shane Harter
 * @since 2011-07-28
 */
class Core_Lock_Null extends Core_Lock_Lock implements Core_PluginInterface
{
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
	
	protected function get()
	{
		// False is a good thing -- it means no lock was detected. 
		return false;
	}
}