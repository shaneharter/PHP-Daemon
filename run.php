<?php
require_once 'error_handlers.php';
require_once 'config.php';

// The daemon needs to know from which file it was executed:  
App_Example::setFilename(__file__);

// The run() method will start the daemon loop. 
App_Example::getInstance()->run();
