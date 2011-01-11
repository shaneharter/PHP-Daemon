<?php
require_once 'App/config.php';
require_once 'App/error_handlers.php';

// Add some email addresses that will be notified on error.
email_on_error('jon_doe@mycompanyhere.php');
email_on_error('2125559999@vtext.com');

// The daemon needs to know from which file it was executed.
App_Example::setFilename(__file__);

// And it needs to have a Memcache namespace.
App_Example::setMemcacheNamespace('sting_development');

// The run() method will start the daemon loop. 
App_Example::getInstance()->run();