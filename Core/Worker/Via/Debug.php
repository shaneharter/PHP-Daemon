<?php

class Core_Worker_Via_Debug
{
    protected $debug = true;
    const INDENT_DEPTH = 6;

    /**
     * Used to determine which process has access to issue prompts to the debug console.
     * @var Resource
     */
    private $mutex;

    public $consoleshm;

    /**
     * Does this process currently own the semaphore?
     * @var bool
     */
    private $mutex_acquired = false;




}