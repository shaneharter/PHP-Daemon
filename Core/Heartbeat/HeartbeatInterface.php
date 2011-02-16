<?php

interface Core_Heartbeat_HeartbeatInterface
{
	const HEARTBEAT_TTL_SECONDS = 2.0;
	const HEARTBEAT_UNIQUE_ID = 'daemon_heartbeat';
	
	
	public $pid;
	public $daemon_name;
	public function set();
	public function listen();
}