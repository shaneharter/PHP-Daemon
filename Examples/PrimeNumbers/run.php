#!/usr/bin/php
<?php
require_once 'config.php';
require_once 'error_handlers.php';

// The daemon needs to know from which file it was executed.
Daemon::setFilename(__FILE__);

// The run() method will start the daemon loop. 
Daemon::getInstance()->run();