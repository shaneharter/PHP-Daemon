<?php

interface Core_Lock_LockInterface
{
	const LOCK_TTL_SECONDS = 2.0;
	const LOCK_UNIQUE_ID = 'daemon_lock';
	
	public function set();
	public function check();
}