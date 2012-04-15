<?php

/*
 * A good error handling strategy is important.
 * 1. We want a daemon to be very resilient and hard to fail fatally, but when it does fail, we need it to fail loudly. Silent
 * failures are my biggest fear. 
 * 
 * 2. Error handlers are implemented as close to line 1 of your app as possible.
 * 
 * 3. We use all the tools PHP gives us: an error handler, an exception handler, and a global shutdown handler.
 *
 */

/**
 * Override the PHP error handler while still respecting the error_reporting, display_errors and log_errors ini settings
 * 
 * @param $errno
 * @param $errstr
 * @param $errfile
 * @param $errline
 * @return boolean
 */
function daemon_error($errno, $errstr, $errfile, $errline) 
{
	// Respect the error_reporting Level
	if(($errno & error_reporting()) == 0) 
		return;

	$is_fatal = false;
		
    switch ($errno) {
        case E_NOTICE:
        case E_USER_NOTICE:
            $errors = 'Notice';
            break;
        case E_WARNING:
        case E_USER_WARNING:
            $errors = 'Warning';
            break;
        case E_ERROR:
        case E_USER_ERROR:
        	$is_fatal = true;
            $errors = 'Fatal Error';
            break;
        default:
            $errors = 'Unknown';
            break;
	}
	
	$message = sprintf('PHP %s: %s in %s on line %d pid %s', $errors, $errstr, $errfile, $errline, getmypid());
	
    if (ini_get('display_errors')) {
    	echo PHP_EOL, $message, PHP_EOL;
        if ($is_fatal) {
            $e = new Exception;
            echo $e->getTraceAsString(), PHP_EOL;
        }
    }

    if (ini_get('log_errors')) {
        error_log($message);
        if ($is_fatal) {
            $e = new Exception;
            error_log(var_export($e->getTraceAsString(), true));
        }
    }
        
    if ($is_fatal) {
    	exit(1);
    }	
	
    return true;
}

/**
 * When the process exits, check to make sure it wasn't caused by an un-handled error.
 * This will help us catch nearly all types of php errors. 
 * @return void
 */
function daemon_shutdown_function() 
{
    $error = error_get_last();
    
    if (is_array($error) && isset($error['type']) == false)
    	return;
    
    switch($error['type'])
    {
    	case E_ERROR:
    	case E_PARSE:
    	case E_CORE_ERROR:
    	case E_CORE_WARNING:
    	case E_COMPILE_ERROR:
    		
			daemon_error($error['type'], $error['message'], $error['file'], $error['line']);
    }
}

error_reporting(E_WARNING | E_USER_ERROR);
set_error_handler('daemon_error');
register_shutdown_function('daemon_shutdown_function');
