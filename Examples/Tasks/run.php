#!/usr/bin/php
<?php
require_once 'config.php';
require_once 'error_handlers.php';

use Examples\Tasks;

// The run() method will start the daemon loop.
Tasks\ParallelTasks::getInstance()->run();