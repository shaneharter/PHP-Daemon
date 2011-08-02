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
 * Pass as many email addresses as you want as individual arguments. 
 * When you call the function without any params, all stored email addresses will be returned.  
 * @return Array
 */
function email_on_error()
{
	static $queue = array();

	if (func_num_args() == 0)
		return $queue;

	$foo = $queue;
	$queue = func_get_args();
		
	return $foo;
}