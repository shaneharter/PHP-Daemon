<?php

class Core_Lib_Process
{

    public $pid;
    public $group;
    public $microtime;
    public $job;
    public $timeout = 60;
    public $min_ttl = 5;
    private $stop_time = null;

    public function __construct() {
        $this->microtime = microtime(true);
    }

    public function runtime() {
        return microtime(true) - $this->microtime;
    }

    public function running(Core_Worker_Call $call) {
        $this->calls[] = $call->id;
    }

    public function timeout() {
        if ($this->timeout > 0)
            $timeout = min($this->timeout, 60);
        else
            $timeout = 30;

        return $timeout;
    }

    /**
     * Stop the process, using whatever means necessary, and possibly return a textual description
     * @return bool|string
     */
    public function stop() {

        if (!$this->stop_time) {
            $this->stop_time = time();
            @posix_kill($this->pid, SIGTERM);
        }

        if (time() > $this->stop_time + $this->timeout()) {
            $this->kill();
            return "Worker Process '{$this->pid}' Shutdown Timeout: Killing...";
        }

        return null;
    }

    public function kill() {
        @posix_kill($this->pid, SIGKILL);
    }
}
