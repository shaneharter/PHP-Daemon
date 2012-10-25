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
     * Array of callables
     * @var closure[]
     */
    private $parsers = array();

    /**
     * Array of commands and their descriptions
     * @var array
     */
    private $commands = array();

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
     * This callable will be passed $method, $args and should return the number of spaces to indent.
     * Note: The return value will be mod'd using the INDENT_DEPTH constant to ensure we don't just indent perpetually.
     * @var Callable
     */
    public $indent_callback;

    /**
     * The prompt prefix should have any relevant state data. Think about standard bash prompts. You get the cwd, etc, in the prompt.
     * This callable will be passed $method, $args and should return the prompt prefix.
     * @var Callable
     */
    public $prompt_prefix_callback;


    public function __construct($object) {
        if (!is_object($object))
            throw new Exception("DebugShell Failed: You must supply an object to be proxied.");

        $this->object = $object;
    }


    public function __call($method, $args) {
        $o = $this->object;
        $cb = function() use($o, $method, $args) {
            call_user_func_array(array($o, $method), $args);
        };

        $interrupt = null;
        if (isset($this->interrupt_callables[$method]))
            $interrupt = $this->interrupt_callables[$method];

        if (!$this->is_breakpoint_active($method))
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


    public function setup() {
        ini_set('display_errors', 0); // Displayed errors won't break the debug console but it will make it more difficult to use. Tail a log file in another shell instead.
        $ftok = ftok(Core_Daemon::get('filename'), 'D');
        $this->mutex = sem_get($ftok, 1, 0666, 1);
        $this->shm = shm_attach($ftok, 64 * 1024, 0666);

        // Add any default parsers
        $parsers = array();
        $parsers[] = array(
            'regex'       => '/^eval (.*)/i',
            'command'     => 'eval [php]',
            'description' => 'Eval the supplied code. Passed to eval() as-is. Any return values will be printed.',
            'closure'     => function($matches, $printer) {
                $return = @eval($matches[1]);
                if ($return === false)
                    $printer("eval returned false -- possibly a parse error. Check semi-colons, parens, braces, etc.");
                elseif ($return !== null)
                    $printer("eval() returned:" . PHP_EOL . print_r($return, true));

                return false;
            }
        );


        $this->loadParsers($parsers);
    }

    /**
     * Add a parser to the queue. Will be evaluated FIFO.
     * The parser functions will be passed the method, args
     * @param $command
     * @param $callable
     * @param string $description
     */
    public function addParser($regex, $command, $description, $closure) {
        $this->parsers[] = compact('regex', 'command', 'description', 'closure');
    }

    /**
     * Append the given array of parsers to the end of the parser queue
     * Array should contain associative array with keys: regex, command, description, closure
     * @param array $parsers
     * @throws Exception
     */
    public function loadParsers(array $parsers) {
        $test = array_keys(current($parsers));
        $keys = array('regex', 'command', 'description', 'closure');
        if ($test != $keys)
            throw new Exception("Cannot Load Parser Queue: Invalid array format. Expected Keys: " . implode(', ', $test) . " Given Keys: " . implode(', ', $keys));

        $this->parsers = array_merge($this->parsers, $parsers);
    }

    /**
     * Get and Set state variables to share settings for this console across processes
     * @param $key
     * @param null $value
     * @return bool|null
     */
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

    private function get_text_prompt($method, $args) {
        if (isset($this->prompts[$method]))
            if (is_callable($this->prompts[$method]))
                $prompt = $this->prompts[$method]($method, $args);
            else
                $prompt = $this->prompts[$method];

        if (empty($prompt))
            $prompt = sprintf('Call to %s::%s()', get_class($this->object), $method);

        $indenter = $this->indent_callback;
        if (is_callable($indenter) && $this->state('indent')) {
            $indent = $indenter($method, $args);
            if (is_numeric($indent) && $indent > 0)
              $prompt = str_repeat("\t", $indent % self::INDENT_DEPTH) . $prompt;
        }

        $prefixer = $this->prompt_prefix_callback;
        if (is_callable($prefixer))
          $prompt = "[" . $prefixer($method, $args) . "] " . $prompt;

        return "$prompt > ";
    }

    private function print_banner() {
        if ($this->state('banner')) {
            echo PHP_EOL, get_class($this->daemon), ' Debug Console';
            echo PHP_EOL, 'Use `help` for list of commands', PHP_EOL, PHP_EOL;
            $this->state('banner', false);
        }
    }

    public function prompt($method, $args) {
        if(!is_resource($this->shm))
            return true;

        // Each running process will display its own debug console. Use a mutex to serialize the execution and control
        // access to STDIN. If the mutex is currently in use, pause this process while we wait for acquisition. And
        // make sure that the user didn't disable debugging or deactivate this breakpoint in the currently active process
        if (!$this->mutex_acquired) {
            $this->mutex_acquired = sem_acquire($this->mutex);
            if (!$this->is_breakpoint_active($method))
                return true;
        }

        // Pass a simple print-line closure to parsers to use instead of just "echo" or "print"
        $printer = function($message, $maxlen = null) {
            if (empty($message))
                return;

            if ($maxlen && strlen($message) > $maxlen) {
                $message = substr($message, 0, $maxlen-3) . '...';
            }

            echo $message . PHP_EOL;
        };

        try {

            $this->print_banner();
            $pid    = getmypid();
            $prompt = $this->get_text_prompt($method, $args);
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

                // Use the ascii up-arrow key to re-run the last command
                if (substr($input, -2) == '[A') {
                    echo chr(8) . chr(8);
                    $input = $this->state('last');
                    echo $input;
                }elseif(!empty($input))
                    $this->state('last', $input);

                // Validate the input as an expression
                $matches = array();
                foreach ($this->parsers as $parser)
                  if (preg_match($parser['regex'], $input, $matches) == 1) {
                      $break = $parser['closure']($matches, $printer);
                      break;
                  }

                if ($matches)
                    continue;

                // If one of the parsers didn't catch the message
                // fall through to the built-in commands
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
                        $out[] = 'end               End the debugging session, continue the daemon as normal.';
                        $out[] = 'help              Print This Help';
                        $out[] = 'kill              Kill the daemon and all of its worker processes.';
                        $out[] = 'skip              Skip this breakpoint from now on.';
                        $out[] = 'skipfor [n]       Run the daemon (and skip ALL breakpoints) for N seconds, then return to normal break point operation.';
                        $out[] = 'show args         Display any arguments that may have been passed at the breakpoint.';
                        $out[] = 'signal [n]        Send the n signal to the parent daemon.';
                        $out[] = 'shutdown          End Debugging and Gracefully shutdown the daemon after the current loop_interval.';
                        $out[] = 'trace             Print A Stack Trace';;

                        if (is_callable($this->indent_callback))
                            $out[] = 'indent [y|n]      When turned-on, indentation will be used to group messages from the same call in a column so you can easily match them together.';

                        $out[] = '';
                        foreach($this->parsers as $parser)
                          $out[] = sprintf('%s%s', str_pad($parser['command'], 18, ' ', STR_PAD_RIGHT), $parser['description']);

                        $out[] = '';
                        $printer(implode(PHP_EOL, $out));
                        break;

                    case 'indent y':
                        $this->state('indent', true);
                        $printer('Indent enabled');
                        break;

                    case 'indent n':
                        $this->state('indent', false);
                        $printer('Indent disabled');
                        break;

                    case 'show args':
                        $printer(print_r($args, true));
                        break;

                    case 'shutdown':
                        //$this->daemon->shutdown();
                        $printer("Shutdown In Progress... Use `end` command to cease debugging until shutdown is complete.");
                        $break = true;
                        break;

                    case 'trace':
                        $e = new exception();
                        $printer($e->getTraceAsString());
                        break;

                    case 'end':
                        $this->state('enabled', false);
                        $break = true;
                        $printer('Debugging Ended..');
                        $input = true;
                        break;

                    case 'skip':
                        $this->state("skip_$method", true);
                        $break = true;
                        $printer('Breakpoint Turned Off..');
                        $input = true;
                        break;

                    case 'kill':
                        @fclose(STDOUT);
                        @fclose(STDERR);
                        @exec('ps -C "php ' . Core_Daemon::get('filename') . '" -o pid= | xargs kill -9 ');
                        break;

                    case 'y':
                        $input = true;
                        $break = true;
                        break;

                    case 'n':
                        $input = false;
                        $break = true;
                        break;

                    default:
                        if ($input)
                            $printer("Unknown Command! See `help` for list of commands.");
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