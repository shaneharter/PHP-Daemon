<?php
require_once 'App/config.php';
//require_once 'App/error_handlers.php';
error_reporting(E_ALL);
ini_set('display_errors', '1');
// Add some email addresses that will be notified on error.
email_on_error('shane.harter@gmail.com');

// The daemon needs to know from which file it was executed.
App_Example::setFilename(__file__);

// The run() method will start the daemon loop. 
App_Example::getInstance()->run();