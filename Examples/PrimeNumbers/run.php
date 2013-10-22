#!/usr/bin/php
<?php
require_once 'config.php';
require_once 'error_handlers.php';

use Examples\PrimeNumbers;

// The run() method will start the daemon loop. 
PrimeNumbers\Daemon::getInstance()->run();