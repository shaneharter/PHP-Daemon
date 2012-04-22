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

    public $consoleshm;

    /**
     * Does this process currently own the semaphore?
     * @var bool
     */
    private $mutex_acquired = false;

    public function setup() {
        $ftok = ftok(Core_Daemon::filename(), 'D');
        $this->mutex = sem_get($ftok, 1, 0666, 1);
        $this->consoleshm = shm_attach($ftok, 64 * 1024, 0666);
        parent::setup();
    }

    public function __destruct() {
        @shm_remove($this->consoleshm);
        @shm_detach($this->consoleshm);
        parent::__destruct();
    }

    /**
     * Remove and Reset any data in shared resources. A "Hard Reset" of the queue. In normal operation, unless the server is rebooted or a worker's alias changed,
     * you can restart a daemon process without losing buffered calls or pending return values. In some cases you may want to purge the buffer.
     * @param bool $reconnect
     * @return void
     */
    protected function reset_workers($reconnect = false) {
        $prompt = "Reset Workers. Reconnect: " . (int) $reconnect;
        if ($this->prompt($prompt, $reconnect))
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

        if ($forks > 1)
            $prompt = "Forking {$forks} New Worker Processes";
        elseif ($forks > 0)
            $prompt = "Forking 1 New Worker Process";

        if (empty($prompt) || $this->prompt($prompt))
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
        $call_status = $this->calls[$call_id]->status;
        $statuses = array(
            self::UNCALLED  =>  'Daemon sending Call message to Worker',
            self::CALLED    =>  'Worker sending "running" ack message to Daemon',
            self::RUNNING   =>  'Worker sending "return" ack message to Daemon',
        );

        if (isset($statuses[$call_status]))
            $status = $statuses[$call_status];
        else {
            $calltype = gettype($call_id);
            $type = gettype($call_status);
            $status = "Unknown Status. (Status: {$call_status}) (Type: $type) (CallId Type: $calltype)";
        }

        $indent = ($call_id - 2) % 5;
        $indent = str_repeat("\t", $indent);

        $prompt = "{$indent}[{$call_id}] {$status}";
        if ($this->prompt($prompt, $call_id))
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
        $call_status = $message['status'];
        $statuses = array(
            self::CALLED    =>  "This worker will run {$this->calls[$call_id]->method}()..",
            self::RUNNING   =>  "Worker is now running {$this->calls[$call_id]->method}()..",
            self::RETURNED  =>  "Worker has returned from {$this->calls[$call_id]->method}()..",
        );

        if (isset($statuses[$call_status]))
            $status = $statuses[$call_status];
        else {
            $calltype = gettype($call_id);
            $type = gettype($call_status);
            $status = "Unknown Status! (Status: {$call_status}) (Type: $type) (CallId Type: $calltype)";
        }

        $indent = ($call_id - 2) % 5;
        $indent = str_repeat("\t", $indent);
        $prompt = "{$indent}[{$call_id}] {$status}";
        $this->prompt($prompt, $message, function() {
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
        $prompt = ($method == 'execute') ? '' : "->{$method}";

        if ($this->prompt("Call to {$this->alias}{$prompt}()", $args))
            return parent::call($method, $args, $retries, $errors);

        return false;
    }

    /**
     * Run the console with the supplied $prompt
     * @param string $prompt
     * @return string   Returns the raw input
     * @throws Exception
     */
    private function prompt($prompt, $args = null, Closure $on_interrupt = null) {

        $that = $this;
        $daemon = $this->daemon;
        static $state = false;

        // Each running process will display its own debug console. Use a mutex to serialize the execution
        // and control access to STDIN. We use shared memory -- abstracted using the $state closure -- to share settings among them
        if (!$state) {
            $state = function($key, $value = null) use ($that, $daemon) {
                static $state = false;
                $defaults = array(
                    'parent'  => $daemon->parent_pid(),
                    'enabled' => true,
                    'indent'  => true,
                    'last'    => '',
                    'banner'  => true,
                );

                if (shm_has_var($that->consoleshm, 1))
                    $state = shm_get_var($that->consoleshm, 1);
                else
                    $state = $defaults;

                // If the process was kill -9'd we might have settings from last debug session hanging around.. wipe em
                if ($state['parent'] != $daemon->parent_pid()) {
                    $state = $defaults;
                    shm_put_var($that->consoleshm, 1, $state);
                }

                if ($value === null)
                    return $state[$key];

                $state[$key] = $value;
                return shm_put_var($that->consoleshm, 1, $state);
            };
        }

        if (!$state('enabled'))
            return false;

        if (!$this->mutex_acquired) {
            $this->mutex_acquired = sem_acquire($this->mutex);
            // Just in case another process changed settings while we were waiting for the mutex...
            if (!$state('enabled'))
                return false;
        }

        if ($state('banner')) {
            echo PHP_EOL, get_class($this->daemon), ' Debug Console';
            echo PHP_EOL, 'Use `help` for list of commands', PHP_EOL, PHP_EOL;
            $state('banner', false);
        }

        try {

            if (!$state('enabled'))
                return false;

            if (!$state('indent'))
                $prompt = str_replace("\t", '', $prompt);

            $pid    = $this->daemon->pid();
            $dw     = ($this->daemon->is_parent()) ? 'D' : 'W';
            $prompt = "[$this->alias $pid $dw] $prompt > ";
            $break  = false;

            // Commands that set $break=true will continue forward from the command prompt.
            // Otherwise it will just do the action (or display an error) and then repeat the prompt

            while(!$break) {

                echo $prompt;
                $input = trim(fgets(STDIN));
                $matches = false;
                $message = '';

                if (substr($input, -2) == '[A') {
                    $input = $state('last');
                } else {
                    $state('last', $input);
                }

                // Validate the input as an expression

                if (!$matches && preg_match('/^show local (\d+)/i', $input, $matches) == 1) {
                    if (!is_array($this->calls)) {
                        echo "No Calls In Memory", PHP_EOL;
                        continue;
                    }

                    if (isset($this->calls[$matches[1]]))
                        $message = print_r(@$this->calls[$matches[1]], true);
                    else
                        $message = "Item Does Not Exist";
                }

                if (!$matches && preg_match('/^show (\d+)?/i', $input, $matches) == 1) {
                    if (empty($this->shm)) {
                        echo "Shared Memory Not Connected Yet", PHP_EOL;
                        continue;
                    }

                    if (count($matches) == 1) {
                        $id = 1; // show the header
                    } else {
                        $id = $matches[1];
                    }

                    $message = print_r(@shm_get_var($this->shm, $id), true);
                }

                if (!$matches && preg_match('/^signal (\d+)/i', $input, $matches) == 1) {
                    posix_kill($this->daemon->parent_pid(), $matches[1]);
                    $message = "Signal Sent";
                }

                if (!$matches && preg_match('/^call ([A-Z_0-9]+) (.*)?/i', $input, $matches) == 1) {
                    if (count($matches) == 3) {
                        $args = str_replace(',', ' ', $matches[2]);
                        $args = explode(' ', $args);
                    }

                    $context = ($this instanceof Core_Worker_Debug_ObjectMediator) ? $this->object : $this;
                    $function = array($context, $matches[1]);

                    if (is_callable($function))
                        if (call_user_func_array($function, $args) === true)
                            $message = $break = true;
                    else
                        $message = "Function Not Callable!";
                }

                if (!$matches && preg_match('/^eval (.*)/i', $input, $matches) == 1) {
                    $return = @eval($matches[1]);
                    switch($return) {
                        case false:
                            $message = "eval returned false -- possibly a parse error. Check semi-colons, parens, braces, etc.";
                            break;
                        case null:
                            $message = "eval() ran successfully";
                            break;
                        default:
                            $message = "eval() returned:" . PHP_EOL . print_r($return, true);
                    }
                    echo PHP_EOL;
                }

                if ($matches) {
                    if ($message)
                        echo $message, PHP_EOL;

                    continue;
                }

                // Wasn't an expression.
                // Validate input as a command.

                switch(strtolower($input)) {
                    case 'help':
                        $out = array();
                        $out[] = 'Debugging a multi-process application can be far more challenging than debugging other applications you may have built using PHP. ';
                        $out[] = 'These tools were built to debug the daemon library and refined for your use. The key function here is allowing you to 1) See what messages are being passed';
                        $out[] = 'between processes and 2) Inspect the contents of the shared memory the processes use as well as their local in-process cache of it.';
                        $out[] = 'For a debugging guide, see: ';
                        $out[] = 'https://github.com/shaneharter/PHP-Daemon/wiki/Debugging-Workers';
                        $out[] = '';
                        $out[] = 'Available Commands:';
                        $out[] = 'y                 Step to the next break point';
                        $out[] = 'n                 Interrupt';
                        $out[] = 'call [f] [a,b..]  Call a worker\'s function in the local process, passing remaining values as args. Return true: a "continue" will be implied. Non-true: keep you at the prompt';
                        $out[] = 'cleanipc          Clean all systemv resources including shared memory and message queues. Does not remove semaphores. REQUIRES CONFIRMATION.  ';
                        $out[] = 'end               End the debugging session for this process, continue the daemon as normal.';
                        $out[] = 'eval [php]        Eval the supplied code. Passed to eval() as-is. Any return values will be printed. Run context is the Core_Worker_Mediator class.';
                        $out[] = 'help              Print This Help';
                        $out[] = 'indent [y|n]      When turned-on, indentation will be used to group messages from the same call in a column so you can easily match them together.';
                        $out[] = 'kill              Kill all PHP processes - Could effect non-daemon-related processes.';
                        $out[] = 'show [n]          Display the Nth item in shared memory. If no ID is passed, `show` will show the shared memory header.';
                        $out[] = 'show args         Display any arguments that may have been passed at the breakpoint.';
                        $out[] = 'show local [n]    Display the Nth item in local memory - from the $this->calls array';
                        $out[] = 'signal [n]        Send the n signal to the parent daemon.';
                        $out[] = 'shutdown          End Debugging and Gracefully shutdown the daemon after the current loop_interval.';
                        $out[] = 'status            Display current process stats';
                        $out[] = 'trace             Print A Stack Trace';
                        $out[] = 'types             Display a table of message types and statuses so you can figure out what they mean.';
                        $out[] = '';
                        $message = implode(PHP_EOL, $out);
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
                        $message = implode(PHP_EOL, $out);
                        break;

                    case 'indent y':
                        $state('indent', true);
                        $message = 'Indent enabled';
                        break;

                    case 'indent n':
                        $state('indent', false);
                        $message = 'Indent disabled';
                        break;

                    case 'show args':
                        $message = print_r($args, true);
                        break;

                    case 'shutdown':
                        $this->daemon->shutdown();
                        $message = "Shutdown In Progress... Use `end` command to cease debugging until shutdown is complete.";
                        $break = true;
                        break;

                    case 'trace':
                        $e = new exception();
                        $message = $e->getTraceAsString();
                        break;

                    case 'end':
                        $state('enabled', false);
                        $break = true;
                        $message = 'Debugging Ended..';
                        break;

                    case 'status':
                        if ($this->is_parent) {
                            $out = array();
                            $out[] = '';
                            $out[] = 'Daemon Process';
                            $out[] = 'Alias: ' . $this->alias;
                            $out[] = 'IPC ID: ' . $this->id;
                            $out[] = 'Workers: ' . count($this->processes);
                            $out[] = 'Max Workers: ' . $this->workers;
                            $out[] = 'Running Jobs: ' . count($this->running_calls);
                            $out[] = '';
                            $out[] = 'Processes:';
                            if ($this->processes)
                                $out[] = $this->processes;
                            else
                                $out[] = 'None';

                            $out[] = '';
                            $message = implode(PHP_EOL, $out);
                        } else {
                            $out = array();
                            $out[] = '';
                            $out[] = 'Worker Process';
                            $out[] = 'Alias: ' . $this->alias;
                            $out[] = 'IPC ID: ' . $this->id;
                            $out[] = '';
                            $message = implode(PHP_EOL, $out);
                        }
                        break;

                    case 'kill':
                        @fclose(STDOUT);
                        @fclose(STDERR);
                        @exec('killall -9 -v /usr/bin/php');
                        break;

                    case 'cleanipc':
                        if (!$state('warned')) {
                            $message = "WARNING: This will release all Shared Memory and Message Queue IPC resources. Only run this if you want ALL resources released.";
                            $message .= "If this is a production server, you should probably not do this. Does NOT release semaphores. To clean all types, including semaphores, use the scripts/clean_ipc.php tool";
                            $message .= PHP_EOL . PHP_EOL . "Repeat command to proceed with the IPC cleaning.";
                            $state('warned', true);
                            break;
                        }
                        $script = dirname(dirname(dirname(dirname(__FILE__)))) . '/scripts/clean_ipc.php';
                        @passthru("php $script -s --confirm");
                        echo PHP_EOL;
                        break;

                    case 'y':
                        $break = true;
                        break;

                    case 'n':
                        if (is_callable($on_interrupt))
                            $on_interrupt();

                        $break = true;
                        break;

                    default:

                        if ($input)
                            $message = "Unknown Command! See `help` for list of commands.";
                }

                if ($message)
                    echo $message, PHP_EOL;
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