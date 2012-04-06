<?php
require_once 'App/config.php';
require_once 'App/error_handlers.php';

#error_reporting(E_ALL);
#ini_set('display_errors', '1');

// The daemon needs to know from which file it was executed.
App_ExampleWorkers::setFilename(__file__);

// The run() method will start the daemon loop. 
App_ExampleWorkers::getInstance()->run();