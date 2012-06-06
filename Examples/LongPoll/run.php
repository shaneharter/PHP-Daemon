#!/usr/bin/php
<?php
require_once 'config.php';
require_once 'error_handlers.php';

use Examples\LongPoll;

// The daemon needs to know from which file it was executed.
LongPoll\Poller::setFilename(__FILE__);

// The run() method will start the daemon event loop.
LongPoll\Poller::getInstance()->run();