<?php

class Core_Worker_Process
{

    public $pid;
    public $alias;
    public $microtime;
    public $calls = array();

    public function get_call($reverse_index = 0) {
        $index = count($this->calls) - $reverse_index;
        if (isset($this->calls[$index]))
            return $this->calls[$index];

        return null;
    }

    public function runtime() {
        return microtime(true) - $this->microtime;
    }

    public function running(Core_Worker_Call $call) {
        $this->calls[] = $call->id;
    }
}
