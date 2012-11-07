<?php


/**
 * Wrap a supplied object in a commandline debug shell. Calls to the objects public methods will be proxied to the shell
 * where the developer can poke and prod. Designed to be used in a multi-process application like those created when using
 * the Workers feature in a PHP Daemon application. In those cases it's not possible to use a conventional debugger.
 *
 * Easily add custom commands to the shell, custom prompt language, callbacks that handle interrupts, etc.
 *
 * @author Shane Harter
 */
class Core_Lib_DebugShell
{

    const ABORT     = 0;
    const CONT      = 1;
    const CAPTURE   = 2;

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

    public $ftok;

    /**
     * Does this process currently own the semaphore?
     * @var bool
     */
    public $mutex_acquired = null;

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

    public function __destruct() {
        @shm_remove($this->consoleshm);
        @shm_detach($this->consoleshm);
        @sem_remove($this->mutex);
    }

    /**
     * While the prompt() method can be called from anywhere to simulate "breakpoints" in your code, this class is designed foremost
     * as a simple mediator between callers and the given $object instance variable.
     *
     * The __call method will do the hard work of implementing the proxy and acting on the commands returned from prompt().
     *
     * @param $method
     * @param $args
     * @return bool|mixed|null|void
     */
    public function __call($method, $args) {
        $o = $this->object;
        $cb = function() use($o, $method, $args) {
            return call_user_func_array(array($o, $method), $args);
        };

        $interrupt = null;
        if (isset($this->interrupt_callables[$method]))
            $interrupt = $this->interrupt_callables[$method];

        if (!$this->is_breakpoint_active($method))
            return $cb();

        switch($this->prompt($method, $args)) {
            case self::CONT:
                return $cb();

            case self::CAPTURE:
                $return = $cb();
                echo "\nReturn Value:";
                print_r($return);
                echo "\n";
                if ($this->prompt(self::CAPTURE, null))
                    return $return;

                break;
        }

        if(is_callable($interrupt))
            return $interrupt();

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

    /**
     * Setup the debug shell: Attach any shared resources and register any prompts or parsers.
     * @return void
     */
    public function setup_shell() {
        ini_set('display_errors', 0); // Displayed errors won't break the debug console but it will make it more difficult to use. Tail a log file in another shell instead.
        $this->ftok = ftok(Core_Daemon::get('filename'), 'D');
        $this->mutex = sem_get($this->ftok, 1, 0666, 1);
        $this->shm = shm_attach($this->ftok, 64 * 1024, 0666);

        $shell = $this;
        $object = $this->object;
        $daemon = $this->daemon;

        $this->prompts[self::CAPTURE] = 'Pass-thru captured return value?';

        // Add any default parsers
        $parsers = array();
        $parsers[] = array(
            'regex'       => '/^eval (.*)/i',
            'command'     => 'eval [php]',
            'description' => 'Eval the supplied code. Passed to eval() as-is. Any return values will be printed. In this context, $shell, $object and $daemon objects are available',
            'closure'     => function($matches, $printer) use($shell, $object, $daemon){
                $return = @eval($matches[1]);
                if ($return === false)
                    $printer("eval returned false -- possibly a parse error. Check semi-colons, parens, braces, etc.");
                elseif ($return !== null)
                    $printer("eval() returned:" . PHP_EOL . print_r($return, true));
                else
                    echo PHP_EOL;

                return false;
            }
        );

        $parsers[] = array(
            'regex'       => '/^signal (\d+)/i',
            'command'     => 'skipfor [n]',
            'description' => 'Run the daemon (and skip ALL breakpoints) for N seconds, then return to normal break point operation.',
            'closure'     => function($matches, $printer) {
                posix_kill(Core_Daemon::get('parent_pid'), $matches[1]);
                $printer("Signal Sent");
            }
        );

        $parsers[] = array(
            'regex'       => '/^skipfor (\d+)/i',
            'command'     => 'signal [n]',
            'description' => 'Send the n signal to the parent daemon.',
            'closure'     => function($matches, $printer) use($shell) {
                $time = time() + $matches[1];
                $shell->debug_state("skip__until", $time);
                $printer("Skipping Breakpoints for $matches[1] seconds. Will resume at " . date('H:i:s', $time));
            }
        );

        $this->loadParsers($parsers);
    }

    /**
     * Return a thread-aware monotonically incrementing integer. Optionally supply a $key to cache the integer assignment
     * and return that to subsequent requests with the same key.
     *
     * @param string $key  If we've already assigned an integer to this key, return that. Otherwise, assign, cache and return it.
     * @return integer
     */
    public function increment_indent($key = null) {
        $i = 1 + $this->debug_state('indent_incrementor', null, 0);
        if ($key === null) {
            $this->debug_state('indent_incrementor', $i);
            return $i;
        }

        $map = $this->debug_state('indent_map', null, array());
        if (!isset($map[$key])) {
            $map[$key] = $i;
            $this->debug_state('indent_map', $map);
            $this->debug_state('indent_incrementor', $i);
        }

        return $map[$key];
    }


    /**
     * Add a parser to the queue. Will be evaluated FIFO.
     * The parser functions will be passed the method, args
     * @param $command
     * @param $callable
     * @param string $description
     */
    public function addParser ($regex, $command, $description, $closure) {
        $this->parsers[] = compact('regex', 'command', 'description', 'closure');
    }

    /**
     * Append the given array of parsers to the end of the parser queue
     * Array should contain associative array with keys: regex, command, description, closure
     * @param array $parsers
     * @throws Exception
     */
    public function loadParsers (array $parsers) {
        $test = array_keys(current($parsers));
        $keys = array('regex', 'command', 'description', 'closure');
        if ($test != $keys)
            throw new Exception("Cannot Load Parser Queue: Invalid array format. Expected Keys: " . implode(', ', $test) . " Given Keys: " . implode(', ', $keys));

        $this->parsers = array_merge($this->parsers, $parsers);
    }

    /**
     * Acquire the mutex. If it's acquired elsewhere, method will block until the mutex is acquired.
     * Note: this method is not thread-aware. The point of caching the pid the mutex was assigned to
     * is to avoid problems where a mutex is acquired, the process forks, and the child thinks IT owns the mutex.
     * @return bool
     */
    private function mutex_acquire () {
        $pid = getmypid();
        if ($pid == $this->mutex_acquired)
            return true;

        if (sem_acquire($this->mutex))
            $this->daemon->log("Mutex Granted");
        else
            $this->daemon->log("Mutex Grant Failed");

            //throw new Exception("Cannot acquire mutex: Unknown Error.");

        $this->mutex_acquired = $pid;
        return true;
    }

    /**
     * Release the mutex
     * @return void
     */
    private function mutex_release () {
        @sem_release($this->mutex);
        $this->mutex_acquired = false;
        $this->daemon->log('Mutex Released');
    }


    /**
     * Get and Set state variables to share settings for this console across processes
     * @param $key
     * @param null $value
     * @return bool|null
     */
    public function debug_state ($key, $value = null, $default = null) {
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
                $this->daemon->log("State get $key = " . $state[$key]);
            else
                $this->daemon->log("State get $key = [$default]");
        else
            $this->daemon->log("State SET $key=$value");

        if ($value === null)
            if (isset($state[$key]))
                return $state[$key];
            else
                return $default;

        $state[$key] = $value;
        return shm_put_var($this->shm, 1, $state);
    }

    /**
     * Determine if a prompt should be displayed. There are several ways to skip/suppress prompts:
     *
     * 1. Disable debugging.
     * 2. Add a given $method to the blacklist at design-time.
     * 3. At runtime, you can temporarily add a $method to the blacklist with the "skip" command.
     * 4. At runtime, you can temporarily disable ALL prompts for a duration using the "skipfor" command.
     *
     * @param $method
     * @return bool
     */
    private function is_breakpoint_active($method) {
        $a = !in_array($method, $this->blacklist);
        $b = $this->debug_state('enabled');
        $c = !$this->debug_state("skip_$method");
        $d = $this->debug_state('skip__until') === null || $this->debug_state('skip__until') < time();
        return $a && $b && $c && $d;
    }

    /**
     * Display the prompt.
     * If a prompt has been added for this $method (either as a closure or a static textual prompt), use it. Otherwise
     * use a default prompt.
     *
     * Supports indentation of the prompt if an indent_callback has been registered. The idea is to visually group
     * prompts together at a specific indent level to more easily follow along. It could be something like each process
     * has its own indent level, or each prompt relating to a specific task or item could have its own, etc. To do this,
     * indent_callback must return an integer to indicate the number of tab chars that will be parsed into the prompt.
     *
     * Also supports a prefix that can be shared across all prompts by registering a prompt_prefix_callback.
     *
     * @param $method
     * @param $args
     * @return string
     */
    private function get_text_prompt($method, $args) {
        if (isset($this->prompts[$method]))
            if (is_callable($this->prompts[$method]))
                $prompt = $this->prompts[$method]($method, $args);
            else
                $prompt = $this->prompts[$method];

        if (empty($prompt))
            $prompt = sprintf('Call to %s::%s()', get_class($this->object), $method);

        $indenter = $this->indent_callback;
        if (is_callable($indenter) && $this->debug_state('indent')) {
            $indent = $indenter($method, $args);
            if (is_numeric($indent) && $indent > 0)
              $prompt = str_repeat("\t", $indent % self::INDENT_DEPTH) . $prompt;
        }

        $prefixer = $this->prompt_prefix_callback;
        if (is_callable($prefixer))
          $prompt = "[" . $prefixer($method, $args) . "] " . $prompt;

        return "$prompt > ";
    }

    /**
     * Print a simple banner when the console starts
     *
     * @return void
     */
    private function print_banner() {
        if ($this->debug_state('banner')) {
            echo PHP_EOL, 'PHP Daemon - Worker Debug Console';
            echo PHP_EOL, 'Use `help` for list of commands', PHP_EOL, PHP_EOL;
            $this->debug_state('banner', false);
        }
    }

    /**
     * Display a command prompt, block on input from STDIN, then parse and execute the specified commands.
     *
     * Multiple processes share a single command prompt by accessing a semaphore identified by the current application.
     * This method will block the process while it waits for the mutex, and then again while it waits for input on STDIN.
     *
     * The text of the prompt itself will be written when get_text_prompt() is called. Custom prompts for a given $method
     * can be added to the $prompts array.
     *
     * Several commands are built-in, and additional commands can be added with addParser().
     *
     * Parsers can either:
     * 1. Continue from the prompt.
     * 2. Abort from the prompt. Call any interrupt_callable that may be registered for this $method.
     * 3. Take some action or perform some activity and then return to the same prompt for additional commands.
     *
     * @param $method
     * @param $args
     * @return bool|int|mixed|null
     * @throws Exception
     */
    public function prompt($method, $args) {

        if(!is_resource($this->shm))
            return true;

        // The single debug shell is shared across the parent and all worker processes. Use a mutex to serialize
        // access to the shell. If the mutex isn't owned by this process, this will block until this process acquires it.
        $this->mutex_acquire();
        if (!$this->is_breakpoint_active($method)) {
            $this->mutex_release();
            return true;
        }

        // Pass a simple print-line closure to parsers to use instead of just "echo" or "print"
        $printer = function($message, $maxlen = null) {
            if (empty($message))
                return;

            if ($maxlen && strlen($message) > $maxlen) {
                $message = substr($message, 0, $maxlen-3) . '...';
            }

            $message = str_replace(PHP_EOL, PHP_EOL . ' ', $message);
            echo " $message\n\n";
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

                // Use the familiar bash !! to re-run the last command
                if (substr($input, -2) == '!!')
                    $input = $this->debug_state('last');
                elseif(!empty($input))
                    $this->debug_state('last', $input);

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
                        $out[] = 'capture           Call the current method and capture its return value. Will print_r the return value and return a prompt.';
                        $out[] = 'end               End the debugging session, continue the daemon as normal.';
                        $out[] = 'help              Print This Help';
                        $out[] = 'kill              Kill the daemon and all of its worker processes.';
                        $out[] = 'skip              Skip this breakpoint from now on.';
                        $out[] = 'shutdown          End Debugging and Gracefully shutdown the daemon after the current loop_interval.';
                        $out[] = 'trace             Print A Stack Trace';;

                        if (is_callable($this->indent_callback))
                            $out[] = 'indent [y|n]      When turned-on, indentation will be used to group messages from the same call in a column so you can easily match them together.';

                        $out[] = '';
                        foreach($this->parsers as $parser)
                          $out[] = sprintf('%s%s', str_pad($parser['command'], 18, ' ', STR_PAD_RIGHT), $parser['description']);

                        $out[] = '';
                        $out[] = '!!                Repeat previous command';
                        $printer(implode(PHP_EOL, $out));
                        break;

                    case 'indent y':
                        $this->debug_state('indent', true);
                        $printer('Indent enabled');
                        break;

                    case 'indent n':
                        $this->debug_state('indent', false);
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
                        $this->debug_state('enabled', false);
                        $break = true;
                        $printer('Debugging Ended..');
                        $input = true;
                        break;

                    case 'skip':
                        $this->debug_state("skip_$method", true);
                        $printer('Breakpoint "' . $method . '" Turned Off..');
                        $break = true;
                        $input = true;
                        break;

                    case 'kill':
                        @fclose(STDOUT);
                        @fclose(STDERR);
                        @exec('ps -C "php ' . Core_Daemon::get('filename') . '" -o pid= | xargs kill -9 ');
                        break;

                    case 'capture':
                        $backtrace = debug_backtrace();
                        if ($backtrace[1]['function'] !== '__call' || $method == self::CAPTURE) {
                            $printer('Cannot capture this :(');
                            break;
                        }

                        $input = self::CAPTURE;
                        $break = true;
                        break;

                    case 'y':
                        $input = self::CONT;
                        $break = true;
                        break;

                    case 'n':
                        $input = self::ABORT;
                        $break = true;
                        break;

                    default:
                        if ($input)
                            $printer("Unknown Command! See `help` for list of commands.");
                }
            }

        } catch (Exception $e) {
            $this->mutex_release();
            throw $e;
        }

        $this->mutex_release();
        return $input;
    }

}