#!/usr/bin/php
<?php
require_once 'config.php';
require_once 'error_handlers.php';

use Examples\HashServer;


// The daemon needs to know from which file it was executed.
HashServer\Daemon::set_filename(__FILE__);

// The run() method will start the daemon loop. 
HashServer\Daemon::getInstance()->run();