<?php

class Core_Worker_Call extends stdClass
{

    public $return;
    public $args;

    public $method;
    public $status;
    public $queue;
    public $pid;
    public $id;
    public $size          = 64;
    public $retries       = 0;
    public $errors        = 0;
    public $gc            = false;
    public $time          = array();


    public function __construct($id, $method = null, Array $args = null) {
        $this->id          = $id;
        $this->method      = $method;
        $this->args        = $args;
        $this->update_size();
        $this->uncalled();
    }

//    public function __get($k) {
//        if (in_array($k, get_object_vars($this)))
//            return $this->{$k};
//
//        return null;
//    }

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

    /**
     * Merge in data from the supplied $call into this call
     * @param Core_Worker_Call $call
     * @return Core_Worker_Call
     */
    public function merge(Core_Worker_Call $call) {
        // This could end up being more sophisticated and complex.
        // But for now, the only modifications to this struct in the worker are timestamps at status changes.
        $this->time[self::CALLED] = $call->time[self::CALLED];
        return $this;
    }

    public function is_active() {
        return !in_array($this->status, array(self::TIMEOUT, self::RETURNED, self::CANCELLED));
    }

    /**
     * Reduce the memory footprint of this call by unsetting argument & return details that could be memory intensive.
     * All other meta-data of the call will be preserved for analytical purposes. Sets a $gc flag upon completion.
     * @return bool
     */
    public function gc() {
        if ($this->gc || $this->is_active())
            return false;

        unset($this->args, $this->return);
        $this->gc = true;
        return true;
    }

    /**
     * Return a message header with pertinent details. Using the SysV via, for example, this is sent on the mq,
     * and the message body is set on shm.
     * @return array
     */
    public function header() {
        return array (
            'call_id'   => $this->id,
            'status'    => $this->status,
            'microtime' => $this->time[$this->status],
            'pid'       => getmypid(),
            // I really have no idea why we are sending the pid along in the message header like this, but I assume
            // there's a good reason so for now I'll just continue to include it.
        );
    }

    public function timeout($microtime = null) {
        return $this->status(Core_Worker_Mediator::TIMEOUT,    $microtime);
    }

    public function cancelled($microtime = null) {
        return $this->status(Core_Worker_Mediator::CANCELLED,  $microtime);
    }

    public function returned($return, $microtime = null) {
        $this->return = $return;
        $this->update_size();
        return $this->status(Core_Worker_Mediator::RETURNED,   $microtime);
    }

    public function running($microtime = null) {
        $this->pid = getmypid();
        return $this->status(Core_Worker_Mediator::RUNNING,    $microtime);
    }

    public function called($microtime = null) {
        return $this->status(Core_Worker_Mediator::CALLED,     $microtime);
    }

    public function uncalled($microtime = null) {
        return $this->status(Core_Worker_Mediator::UNCALLED,   $microtime);
    }

    public function status($status, $microtime = null) {
        if ($status < $this->status)
            throw new Exception(__METHOD__ . " Failed: Cannot Rewind Status. Current Status: {$this->status} Given: {$status}");

        if ($microtime === null)
            $microtime = microtime(true);

        $this->status = $status;
        $this->queue = Core_Worker_Mediator::$queue_map[$status];
        $this->time[$status] = $microtime;

        return $this;
    }

    private function update_size() {
        $this->size = strlen(print_r($this, true));
    }

}