<?php

class Core_Lib_DebugShell
{

    const INDENT_DEPTH = 6;

    /**
     * The object that is being proxied by this shell
     * @var stdClass
     */
    public $object;

    /**
     * A simple way to toggle debugging on & off
     * @var bool
     */
    public $debug = true;

    /**
     * Used to determine which process has access to issue prompts to the debug console.
     * @var Resource
     */
    private $mutex;

    /**
     * Shared Memory resource to store settings for this debug shell that can be shared across all processes
     * using it.
     * @var Resource
     */
    public $shm;

    /**
     * Does this process currently own the semaphore?
     * @var bool
     */
    private $mutex_acquired = false;


    public $daemon;

    /**
     * List of methods to exclude from debugging -- will be passed directly to the proxied $object
     * @var array
     */
    public $blacklist = array();

    /**
     * Associative array of method names and their corresponding prompt -- If ommitted the method name will be used
     * to form a generic prompt.
     * @example ['setup', 'Setup the object & connect to the database']
     * @var array
     */
    public $prompts = array();

    /**
     * Associative array of method names and a callable that will be called if that method is interrupted.
     * For example, it could be used to print a special message if a given method is interrupted, or clean up unused resources.
     * @var Closure[]
     */
    public $interrupt_callables = array();

    /**
     * It can be helpful to group multiple lines of the same logical event using indentation. But the rules to distinguish
     * like-events are unique to each application. You can provide a callback that will be passed the method and args, and
     * should return an integer: the number of tab characters to indent the prompt .
     * @var Closure|Callback
     */
    public $indent_callback;



    public function __call($method, $args) {

        $o = $this->object;
        $cb = function() use($o, $method, $args) {
            call_user_func_array(array($o, $method), $args);
        };

        $interrupt = null;
        if (isset($this->interrupt_callables[$method]))
            $interrupt = $this->interrupt_callables[$method];

        if ($this->is_breakpoint_active($method))
            return $cb();

        if ($this->prompt($method, $args))
            return $cb();
        elseif(is_callable($interrupt))
            $interrupt();

        return null;
    }

    public function __get($k) {
        if (in_array($k, get_object_vars($this->object)))
            return $this->object->{$k};

        return null;
    }

    public function __set($k, $v) {
        if (in_array($k, get_object_vars($this->object)))
            return $this->object->{$k} = $v;

        return null;
    }


    private function state($key, $value = null) {
        static $state = false;
        $defaults = array(
            'parent'  => Core_Daemon::get('parent_pid'),
            'enabled' => true,
            'indent'  => true,
            'last'    => '',
            'banner'  => true,
            'warned'  => false,
        );

        if (shm_has_var($this->shm, 1))
            $state = shm_get_var($this->shm, 1);
        else
            $state = $defaults;

        // If the process was kill -9'd we might have settings from last debug session hanging around.. wipe em
        if ($state['parent'] != Core_Daemon::get('parent_pid')) {
            $state = $defaults;
            shm_put_var($this->shm, 1, $state);
        }

        if ($value === null)
            if (isset($state[$key]))
                return $state[$key];
            else
                return null;

        $state[$key] = $value;
        return shm_put_var($this->shm, 1, $state);
    }

    private function is_breakpoint_active($method) {
        $a = !in_array($method, $this->blacklist);
        $b = $this->state('enabled');
        $c = !$this->state("skip_$method");
        $d = $this->state('skip_until') === null || $this->state('skip_until') < time();
        return $a && $b && $c && $d;
    }

    private function get_text_prompt($method) {
        if (isset($this->prompts[$method]))
            return $this->prompts[$method];

        return sprintf('Call to %s::%s()', get_class($this->object), $method);
    }

    private function prompt($method, $args) {

        $prompt = $this->get_text_prompt($method);

        // @todo we might not need this, can we just use $method?
        $breakpoint = new Exception();
        $breakpoint = $breakpoint->getTrace();
        $breakpoint = sprintf('%s:%s', $breakpoint[0]['file'], $breakpoint[0]['line']);

        if(!is_resource($this->shm)) {
            return true;
        }

        // Each running process will display its own debug console. Use a mutex to serialize the execution and control
        // access to STDIN. If the mutex is currently in use, pause this process while we wait for acquisition. And
        // make sure that the user didn't disable debugging or deactivate this breakpoint in the currently active process
        if (!$this->mutex_acquired) {
            $this->mutex_acquired = sem_acquire($this->mutex);
            if (!$this->is_breakpoint_active($method))
                return true;
        }

        if ($this->state('banner')) {
            echo PHP_EOL, get_class($this->daemon), ' Debug Console';
            echo PHP_EOL, 'Use `help` for list of commands', PHP_EOL, PHP_EOL;
            $this->state('banner', false);
        }

        try {

            if (!$this->state('indent'))
                $prompt = str_replace("\t", '', $prompt);

            $pid    = $this->daemon->pid();
            $dw     = (Core_Daemon::is('parent')) ? 'D' : 'W';
            $prompt = "[$this->alias $pid $dw] $prompt > ";
            $break  = false;

            // We have to clear the buffer of any input that occurred in the terminal in the space after they submitted their last
            // command and before this new prompt. Otherwise it'll be read from fgets below and probably ruin everything.
            stream_set_blocking(STDIN, 0);
            while(fgets(STDIN)) continue;
            stream_set_blocking(STDIN, 1);

            // Commands that set $break=true will continue forward from the command prompt.
            // Otherwise it will just do the action (or display an error) and then repeat the prompt

            while(!$break) {

                echo $prompt;
                $input = trim(fgets(STDIN));
                $input = preg_replace('/\s+/', ' ', $input);

                $matches = false;
                $message = '';

                if (substr($input, -2) == '[A') {
                    $input = $state('last');
                } elseif(!empty($input)) {
                    $state('last', $input);
                }

                // Validate the input as an expression



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
                        $out[] = 'For the PHP Simple Daemon debugging guide, see: ';
                        $out[] = 'https://github.com/shaneharter/PHP-Daemon/wiki/Debugging-Workers';
                        $out[] = '';
                        $out[] = 'Available Commands:';
                        $out[] = 'y                 Step to the next break point';
                        $out[] = 'n                 Interrupt';
                        $out[] = '';
                        $out[] = 'call [f] [a,b..]  Call a worker\'s function in the local process, passing remaining values as args. Return true: a "continue" will be implied. Non-true: keep you at the prompt';
                        $out[] = 'cleanipc          Clean all systemv resources including shared memory and message queues. Does not remove semaphores. REQUIRES CONFIRMATION.  ';
                        $out[] = 'end               End the debugging session, continue the daemon as normal.';
                        $out[] = 'eval [php]        Eval the supplied code. Passed to eval() as-is. Any return values will be printed. Run context is the Core_Worker_Mediator class.';
                        $out[] = 'help              Print This Help';
                        $out[] = 'indent [y|n]      When turned-on, indentation will be used to group messages from the same call in a column so you can easily match them together.';
                        $out[] = 'kill              Kill the daemon and all of its worker processes.';
                        $out[] = 'skip              Skip this breakpoint from now on.';
                        $out[] = 'skipfor [n]       Run the daemon (and skip ALL breakpoints) for N seconds, then return to normal break point operation.';
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
                        $out[] = '4     Cancelled';
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
                        $input = true;
                        break;

                    case 'skip':
                        $state("skip_$breakpoint", true);
                        $break = true;
                        $message = 'Breakpoint Turned Off..';
                        $input = true;
                        break;

                    case 'status':
                        if (Core_Daemon::is('parent')) {
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
                        @exec('ps -C "php ' . $this->daemon->get('filename') . '" -o pid= | xargs kill -9 ');
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
                        $input = true;
                        break;

                    case 'n':
                        if (is_callable($on_interrupt))
                            $on_interrupt();

                        $input = false;
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