<?php

date_default_timezone_set('America/New_York');

// Define path to project root directory
define("BASE_PATH", dirname(dirname(__FILE__)));

// Define application environment
defined('AE') || define('AE', (getenv('AE') ? getenv('AE') : 'production'));

// Define a simple Auto Loader
set_include_path(implode(PATH_SEPARATOR, array(
    realpath(BASE_PATH . '/Core'),
    realpath(BASE_PATH . '/App'),
    get_include_path(),
)));

function __autoload($className)
{
    $classFile = str_replace("_", "/", $className) . ".php";
    require_once $classFile;
}

/**
 * Call this method as many times as you want to push error messages onto the stack. 
 * When a user error or php error occurs, the system will attempt to send an email to all of them.
 * @param $email_address A single email address you wish to be emailed on error. Call as many times as you want to add all addresses.
 * @return Array
 */
function email_on_error($email_address = false)
{
	static $queue = array();
	
	if ($email_address == false)
		return $queue;
	
	if (false == in_array($email_address, $queue))
		$queue[] = $email_address;
}

