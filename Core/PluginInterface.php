<?php

interface Core_PluginInterface
{
	/**
	 * Called on Construct or Init
	 * @return void
	 */	
	public function setup();
	
	/**
	 * Called on Destruct
	 * @return void
	 */
	public function teardown();
	
	/**
	 * This is called during object construction to validate any dependencies
	 * @return Array	Return array of error messages (Think stuff like "GD Library Extension Required" or "Cannot open /tmp for Writing") or an empty array
	 */
	public function check_environment();
	
	/**
	 * This is called after execute() is called each interval. Be cautious that anything coded here does not use up too much time in the interval. 
	 * @return void
	 */
	public function run();	
}