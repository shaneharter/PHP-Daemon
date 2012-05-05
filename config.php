<?php

/**
 * This daemon, plugins or workers do not explicitly rely on anything here. You can integrate the Daemon library
 * into any existing bootstrap code that you may have.
 *
 * Note: When you create your daemon you will certainly need to use the filesystem from time to time. It's a good practice
 * to use a BASE_PATH constant of some kind. If you use "./" or similar relative paths, you will probably have trouble if/when
 * your daemon process is started in different ways: From the command-line ("./" will work) to Crontab (it will not), to
 * supervisord and daemontools (your mileage may vary).
 *
 */

ini_set('error_log', '/var/log/phpcli');
ini_set('display_errors', 1);

date_default_timezone_set('America/Los_Angeles');

// Define path to project root directory
define("BASE_PATH", dirname(__FILE__));

// Define application environment
defined('AE') || define('AE', (getenv('AE') ? getenv('AE') : 'production'));

// Define a simple Auto Loader
set_include_path(implode(PATH_SEPARATOR, array(
    realpath(BASE_PATH . '/Core'),
    realpath(BASE_PATH . '/Example'),
    realpath(BASE_PATH . '/ExampleWorkers'),
    get_include_path(),
)));

function pathify($class_name) {
    return str_replace("_", "/", $class_name) . ".php";
}

function __autoload($class_name)
{
    $classFile = str_replace("_", "/", $class_name) . ".php";
    require_once $classFile;
}

