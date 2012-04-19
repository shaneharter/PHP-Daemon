<?php
/**
 * Overlays a debug console so you can introspect and direct the inter-process communication between workers.
 * Essentially sets "break points" each time a process is forked or messages are passed to/from it.
 * You can use the debug console to step forward, throw an exception, continue and turn off debugging, kill the
 * running daemon, and use several introspection and information commands.
 *
 * When we move to PHP 5.4 later in 2012, this functionality will be re-implemented as a mix-in. It really shouldn't
 * be in the inheritance chain and it does cause problems. I don't want this code to be executed at all when the
 * daemon is in production: for reasons of performance and simplicity so right now we've resorted to having
 * duplicate instances of FunctionMediator and ObjectMediator.
 *
 * @see https://github.com/shaneharter/PHP-Daemon/wiki/Debugging-Workers
 * @author Shane Harter
 */
abstract class Core_Worker_Debug_Mediator extends Core_Worker_Mediator
{
    protected $debug = true;

    /**
     * Used to determine which process has access to issue prompts to the debug console.
     * @var Resource
     */
    private $mutex;

    /**
     * Does this process currently own the semaphore?
     * @var bool
     */
    private $mutex_acquired = false;


    public function setup() {
        $ftok = ftok(Core_Daemon::filename(), 'D');
        $this->mutex = sem_get($ftok, 1, 0666, 1);
        if ($this->is_parent) {
            echo PHP_EOL, get_class($this->daemon), ' Debug Console - ', $this->alias;
            echo PHP_EOL;
        }
        parent::setup();
    }

    /**
     * Remove and Reset any data in shared resources. A "Hard Reset" of the queue. In normal operation, unless the server is rebooted or a worker's alias changed,
     * you can restart a daemon process without losing buffered calls or pending return values. In some cases you may want to purge the buffer.
     * @param bool $reconnect
     * @return void
     */
    protected function reset_workers($reconnect = false) {
        $prompt = "Reset Workers. Reconnect: " . (int) $reconnect;
        if ($this->prompt($prompt))
            parent::reset_workers($reconnect);
    }

    /**
     * Fork an appropriate number of daemon processes. Looks at the daemon loop_interval to determine the optimal
     * forking strategy: If the loop is very tight, we will do all the forking up-front. For longer intervals, we will
     * fork as-needed. In the middle we will avoid forking until the first call, then do all the forks in one go.
     * @return mixed
     */
    protected function fork() {
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

        $prompt = "Forking Worker Processes. (Processes: {$forks})";
        if ($this->prompt($prompt))
            return parent::fork();

        return false;
    }

    /**
     * Send messages for the given $call_id to the right queue based on that call's state. Writes call data
     * to shared memory at the address specified in the message.
     * @param $call_id
     * @return bool
     */
    protected function message_encode($call_id) {
        $statuses = array(
            self::UNCALLED  =>  'Daemon sending Call message to Worker',
            self::CALLED    =>  'Worker sending running ack to Daemon',
            self::RUNNING   =>  'Worker sending return ack to Daemon',
        );
        if (isset($statuses[$this->calls[$call_id]->status]))
            $status = $statuses[$this->calls[$call_id]->status];
        else {
            $calltype = gettype($call_id);
            $type = gettype($this->calls[$call_id]->status);
            $status = "Unknown Status. (Status: {$this->calls[$call_id]->status}) (Type: $type) (CallId Type: $calltype)";
        }
        $prompt = "Msg Sending: {$status} (Call: {$call_id})";
        if ($this->prompt($prompt))
            return parent::message_encode($call_id);

        return false;
    }

    /**
     * Decode the supplied-message. Pulls in data from the shared memory address referenced in the message.
     * @param array $message
     * @return mixed
     * @throws Exception
     */
    protected function message_decode(Array $message) {
        $call_id = parent::message_decode($message);

        $statuses = array(
            self::CALLED    =>  "This worker will run {$this->calls[$call_id]->method}()..",
            self::RUNNING   =>  "Worker is now running {$this->calls[$call_id]->method}()..",
            self::RETURNED  =>  "Worker has returned from {$this->calls[$call_id]->method}()..",
        );

        if (isset($statuses[$this->calls[$call_id]->status]))
            $status = $statuses[$this->calls[$call_id]->status];
        else {
            $calltype = gettype($call_id);
            $type = gettype($this->calls[$call_id]->status);
            $status = "Unknown Status. (Status: {$this->calls[$call_id]->status}) (Type: $type) (CallId Type: $calltype)";
        }

        $prompt = "Msg Received: {$status} (Call: {$call_id})";
        $this->prompt($prompt, function() {
            throw new Exception('User Interrupt! Message Discarded');
        });

        return $call_id;
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
        $status = ($this->is_idle()) ? 'Realtime' : 'Queued';
        if ($this->prompt("Worker Method Called (Method: {$method})"))
            return parent::call($method, $args, $retries, $errors);

        return false;
    }

    /**
     * Run the console with the supplied $prompt
     * @param string $prompt
     * @return string   Returns the raw input
     * @throws Exception
     */
    private function prompt($prompt, Closure $on_interrupt = null) {

        static $warned = false;

        if (!$this->debug)
            return false;

        // Each running process will display its own debug console. Use a mutex to serialize the execution
        // and control access to STDIN.

        if (!$this->mutex_acquired)
            $this->mutex_acquired = sem_acquire($this->mutex);

        try {
            $pid = $this->daemon->pid();
            $dw = ($this->daemon->is_parent()) ? 'D' : 'W';
            $prompt = "\n[$this->alias $pid $dw] $prompt > ";
            $break = false;

            // Only "yes", "no", "end" and "kill" commands will break the input prompt.
            // All other commands are just informative or interactive. ,
            while(!$break) {
                echo $prompt;
                $input = strtolower(trim(fgets(STDIN)));

                if (substr($input, 0, 10) == 'show local') {
                    $id = explode(' ', $input);

                    if (!is_array($this->calls)) {
                        echo "No Calls In Memory";
                        continue;
                    }

                    if (count($id) == 2) {
                        $id = 1; // show the header
                    } else {
                        $id = $id[2];
                    }

                    if (is_numeric($id)) {
                        print_r(@$this->calls[$id]);
                        continue;
                    }
                }

                if (substr($input, 0, 4) == 'show') {
                    $id = explode(' ', $input);

                    if (!$this->id) {
                        echo "Shared Memory Not Connected Yet";
                        continue;
                    }

                    if (count($id) == 1) {
                        $id = 1; // show the header
                    } else {
                        $id = $id[1];
                    }

                    if (is_numeric($id)) {
                        print_r(@shm_get_var($this->shm, $id));
                        continue;
                    }
                }

                if (substr($input, 0, 6) == 'signal') {
                    $id = explode(' ', $input);

                    if (count($id) == 1) {
                        echo "No Signal Provided";
                    } else {
                        $id = $id[1];
                    }

                    if (is_numeric($id)) {
                        posix_kill($this->daemon->parent_pid(), $id);
                        echo "Signal Sent";
                        continue;
                    }
                }

                switch($input) {
                    case 'help':
                        $out = array();
                        $out[] = 'Available Commands:';
                        $out[] = '';
                        $out[] = 'y                 Step to the next break point';
                        $out[] = 'n                 Interrupt';
                        $out[] = 'end               End the debugging session for this process, continue the daemon as normal. (Will have to repeat this command for each running process)';
                        $out[] = 'help              Print This Help';
                        $out[] = 'kill              Kill all PHP processes - Could effect non-daemon-related processes.';
                        $out[] = 'show [n]          Inspect the n item in shared memory. If no ID is passed, `show` will show the shared memory header.';
                        $out[] = 'show local [n]    Inspect the n item in local memory - from the $this->calls array';
                        $out[] = 'signal [n]        Send the n signal to the parent daemon.';
                        $out[] = 'shutdown          End Debugging and Gracefully shutdown the daemon after the current loop_interval.';
                        $out[] = 'strace            Print A Stack Trace';
                        $out[] = 'status            Display current process stats';
                        $out[] = 'types             Display a table of message types and statuses so you can figure out what they mean.';
                        $out[] = '';
                        echo implode(PHP_EOL, $out);
                        break;

                    case 'types':
                        $out = array();
                        $out[] = 'Message Types:';
                        $out[] = '1     Worker Sending "onReturn" message to the Daemon';
                        $out[] = '2     Worker Notifying Daemon that it received the Call message and will now begin work.';
                        $out[] = '3     Daemon sending a Call message to the Worker';
                        $out[] = '';
                        $out[] = 'Statuses:';
                        $out[] = '0     Uncalled';
                        $out[] = '1     Called';
                        $out[] = '2     Running';
                        $out[] = '3     Returned';
                        $out[] = '10    Timeout';
                        $out[] = '';
                        echo implode(PHP_EOL, $out);
                        break;

                    case 'shutdown':
                        $this->daemon->shutdown();
                        $break = true;
                        break;

                    case 'strace':
                        $e = new exception();
                        echo $e->getTraceAsString();
                        break;

                    case 'end':
                        $this->debug = false;
                        $break = true;
                        break;

                    case 'status':
                        if ($this->is_parent) {
                            $out = array();
                            $out[] = '';
                            $out[] = 'Alias: ' . $this->alias;
                            $out[] = 'IPC ID: ' . $this->id;
                            $out[] = 'Workers: ' . count($this->processes);
                            $out[] = 'Max Workers: ' . $this->workers;
                            $out[] = 'Running Jobs: ' . count($this->running_calls);
                            $out[] = '';
                            echo implode(PHP_EOL, $out);
                        } else {
                            echo "Worker Process";
                        }
                        break;

                    case 'kill':
                        @exec('killall -9 -v /usr/bin/php');
                        break;

                    case 'cleanipc':
                        if (!$warned) {
                            echo "WARNING: This will release all SystemV IPC resources: Shared Memory, Message Queues and Semaphores. Only run this if you want ALL resources released.";
                            echo "If this is a production server, you should probably not do this.";
                            echo PHP_EOL, PHP_EOL, "Repeat command to proceed with the IPC cleaning.";
                            $warned = true;
                            break;
                        }
                        $script = dirname(dirname(dirname(dirname(__FILE__)))) . '/scripts/clean_ipc.php';
                        @passthru("php $script --confirm");
                        break;

                    case 'restart':
                        // Obv doesn't work the way we'd want.. the debug console is not attached
                        // to the users shell after the restart, so it's useless.
                        if ($this->is_parent)
                            $this->daemon->restart();
                        else
                            posix_kill($this->daemon->parent_pid(), 1);
                        break;

                    case 'y':
                        $break = true;
                        break;

                    case 'n':

                        if (is_callable($on_interrupt))
                            $on_interrupt();

                        $break = true;
                        $input = false;
                        break;

                    default:
                        if ($input)
                            echo "Unknown Command! See `help` for list of commands. ";
                }
            }
        } catch (Exception $e) {
            @sem_release($this->mutex);
            $this->mutex_acquired = false;
            throw $e;
        }

        @sem_release($this->mutex);
        $this->mutex_acquired = false;
        return $input;
    }
}