<?php

class Core_Worker_Call extends stdClass
{

    public $method;
    public $return;
    public $args;
    public $status;
    public $queue;
    public $pid;
    public $id;
    public $size          = 64;
    public $retries       = 0;
    public $errors        = 0;
    public $gc            = false;
    public $time          = array();


    public function __construct($id, $method, Array $args = null) {
        $this->id          = $id;
        $this->method      = $method;
        $this->args        = $args;
        $this->uncalled();
    }

    public function __set($k, $v) {
        // @todo set the return and args to private, update $size here when they're set:
        // strlen(print_r($call, true))
    }

    public function runtime() {
        switch($this->status) {
            case Core_Worker_Mediator::RUNNING:
                return microtime(true) - $this->time[Core_Worker_Mediator::RUNNING];

            case Core_Worker_Mediator::RETURNED:
                return $this->time[Core_Worker_Mediator::RETURNED] - $this->time[Core_Worker_Mediator::RUNNING];

            default:
                return 0;
        }
    }

// @todo probably remove -- merging 2 should sorta be up to the mediator i think
//    public function merge(Core_Worker_Call $call) {
//        // This could end up being more sophisticated and complex.
//        // But for now, the only modifications to this struct in the worker are timestamps at status changes.
//        $this->time[self::CALLED] = $call->time[self::CALLED];
//    }


    public function timeout($microtime = null) {
        return $this->status(Core_Worker_Mediator::TIMEOUT,    $microtime);
    }

    public function cancelled($microtime = null) {
        return $this->status(Core_Worker_Mediator::CANCELLED,  $microtime);
    }

    public function returned($microtime = null) {
        return $this->status(Core_Worker_Mediator::RETURNED,   $microtime);
    }

    public function running($microtime = null) {
        return $this->status(Core_Worker_Mediator::RUNNING,    $microtime);
    }

    public function called($microtime = null) {
        return $this->status(Core_Worker_Mediator::CALLED,     $microtime);
    }

    public function uncalled($microtime = null) {
        return $this->status(Core_Worker_Mediator::UNCALLED,   $microtime);
    }

    private function status($status, $microtime = null) {
        if ($status < $this->status)
            throw new Exception(__METHOD__ . " Failed: Cannot Rewind Status. Current Status: {$this->status} Given: {$status}");

        if ($microtime === null)
            $microtime = microtime(true);

        $this->status = $status;
        $this->queue = Core_Worker_Mediator::$queue_map[$status];
        $this->time[$status] = $microtime;

        return $this;
    }

}