<?php
require_once 'App/config.php';
require_once 'App/error_handlers.php';

// Add some email addresses that will be notified on error.
email_on_error('shane.harter@gmail.com');

// The daemon needs to know from which file it was executed.
App_QueueReader::setFilename(__file__);

// The run() method will start the daemon loop. 
App_QueueReader::getInstance()->run();