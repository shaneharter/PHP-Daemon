#!/usr/bin/php
<?php
require_once 'config.php';
require_once 'error_handlers.php';

use Examples\LongPoll;

// The run() method will start the daemon event loop.
LongPoll\Poller::getInstance()->run();