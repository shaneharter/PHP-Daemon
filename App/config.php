<?php

ini_set('error_log', '/var/log/phpcli');

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

