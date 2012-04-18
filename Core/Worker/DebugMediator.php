<?php
/**
 * Easily step-through the message-passing and memory sharing
 *
 * @author Shane Harter
 */
abstract class Core_Worker_DebugMediator extends Core_Worker_Mediator
{
    /**
     * Remove and Reset any data in shared resources. A "Hard Reset" of the queue. In normal operation, unless the server is rebooted or a worker's alias changed,
     * you can restart a daemon process without losing buffered calls or pending return values. In some cases you may want to purge the buffer.
     * @param bool $reconnect
     * @return void
     */
    protected function reset_workers($reconnect = false) {
        if ($this->debug) {
            $prompt = "Reset Workers. Reconnect: " . (int) $reconnect;
            if ($this->prompt($prompt) != "y") {
                throw new Exception("User Interrupt! Method:" . __METHOD__);
            }
        }

        parent::reset_workers($reconnect);
    }

    /**
     * Fork an appropriate number of daemon processes. Looks at the daemon loop_interval to determine the optimal
     * forking strategy: If the loop is very tight, we will do all the forking up-front. For longer intervals, we will
     * fork as-needed. In the middle we will avoid forking until the first call, then do all the forks in one go.
     * @return mixed
     */
    protected function fork() {
        if ($this->debug) {
            $processes = count($this->processes);
            if ($this->workers <= $processes)
                return;

            switch ($this->forking_strategy) {
                case self::LAZY:
                    if ($processes > count($this->running_calls))
                        $forks = 0;
                    else
                        $forks = 1;
                    break;
                case self::MIXED:
                    $forks = $this->workers - $processes;
                    break;
                case self::AGGRESSIVE:
                default:
                    $forks = $this->workers;
                    break;
            }

            $prompt = "Forking. Processes: [{$forks}]";
            if ($this->prompt($prompt) != "y") {
                throw new Exception("User Interrupt! Method:" . __METHOD__);
            }
        }
        return parent::fork();
    }

    /**
     * Send messages for the given $call_id to the right queue based on that call's state. Writes call data
     * to shared memory at the address specified in the message.
     * @param $call_id
     * @return bool
     */
    protected function message_encode($call_id) {
        if ($this->debug) {
            $prompt = "Encoding Message [{$call_id}]";
            if ($this->prompt($prompt) != "y") {
                throw new Exception("User Interrupt! Method:" . __METHOD__);
            }
        }
        return parent::message_encode($call_id);
    }

    /**
     * Decode the supplied-message. Pulls in data from the shared memory address referenced in the message.
     * @param array $message
     * @return mixed
     * @throws Exception
     */
    protected function message_decode(Array $message) {
        if ($this->debug) {
            $prompt = "Decoding Message [{$message['call']}]";
            if ($this->prompt($prompt) != "y") {
                throw new Exception("User Interrupt! Method:" . __METHOD__);
            }
        }
        return parent::message_decode($message);

    }

    /**
     * Mediate all calls to methods on the contained $object and pass them to instances of $object running in the background.
     * @param string $method
     * @param array $args
     * @param int $retries
     * @return bool
     * @throws Exception
     */
    protected function call($method, Array $args, $retries=0, $errors=0) {
        if ($this->debug) {
            $status = ($this->is_idle()) ? 'Realtime' : 'Queued';
            $prompt = "Method Call [$method] Status: $status";
            if ($this->prompt($prompt) != "y") {
                throw new Exception("User Interrupt! Method:" . __METHOD__);
            }
        }
        return parent::call($method, $args, $retries, $errors);
    }

    private function prompt($prompt, $valid_inputs = array("y", "n"), $default ="y") {
        $pid = $this->daemon->pid();
        $prompt = "\n[$pid] [$this->alias $this->id] $prompt: ";
        while(!isset($input) || (is_array($valid_inputs) && !in_array($input, $valid_inputs)) || ($valid_inputs == 'is_file' && !is_file($input))) {
            echo $prompt;
            $input = strtolower(trim(fgets(STDIN)));
            if(empty($input) && !empty($default)) {
                $input = $default;
            }
            if($input == 's') {
                $e = new exception();
                $this->log($e->getTraceAsString());
                $input = null;
            }
            if($input == 'end') {
                $this->debug = false;
                $input = 'y';
            }
        }
        return $input;
    }
}