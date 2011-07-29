<?php

abstract class Core_Lock_Lock 
{
	public static $LOCK_TTL_PADDING_SECONDS = 2.0;
	public static $LOCK_UNIQUE_ID = 'daemon_lock';
	
	public $pid;
	public $daemon_name;	
	
	/**
	 * This is added to the const LOCK_TTL_SECONDS to determine how long the lock should last -- any lock provider should be 
	 * self-expiring using these TTL's. If a lock doesn't self expire you're just asking for crash to leave an errant lock behind that has to be 
	 * manually cleared.
	 *   
	 * @var decimal 	Number of seconds the lock should be active -- padded with Core_Lock_Lock::LOCK_TTL_PADDING_SECONDS
	 */
	public $ttl = 0;
	
	abstract public function set();
	abstract public function check();
}