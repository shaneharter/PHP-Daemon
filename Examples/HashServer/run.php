#!/usr/bin/php
<?php
require_once 'config.php';
require_once 'error_handlers.php';

use Examples\HashServer;

// The run() method will start the daemon loop.
HashServer\Daemon::getInstance()->run();