<?php 

declare(ticks = 5);

/**
 * Daemon Base Class with various cool daemon features. Includes a built-in timer:
 * You define an execute() method, and a loop_interval (in seconds) (Could be 1, 10, 60, 3600, 0.5, 0.1, etc). The timer does the rest. 
 * 
 * USAGE: 
 * 1. Implement the setup(), execute() and log_file() methods in your base class. 
 *    - setup() is called automaticaly after __construct() and init() are called, and can be called for you automatically after each ->fork(). 
 *    - execute() is called on whaveter timer you define when you set the loop_interval. 
 *    - log_file() should return the path that all log events will be written-to. Maybe use date('Ymd') to build a simple daily log rotator. 
 * 
 * 2. In your constructor, call parent::__construct() and then set: 
 * 		lock				Several lock providers exist or write your own that extends Core_Lock_Lock. Used to prevent duplicate instances of the daemon. 
 * 		loop_interval			In seconds, how often should the execute() method run? Decimals are allowed. Tested as low as 0.10. 
 * 		auto_restart_interval		In seconds, how often shoudl the daemon restart itself? Must be no lower than the const value in Core_Daemon::MIN_RESTART_SECONDS.  
 * 		email_distribution_list		An array of email addresses that will be alerted when falat errors occur 
 * 
 * 3. Configure any Plugins you want to use. For example, the /Plugins/Ini.php provides integrated, validated ini file loading. Functionality implemented as a plugin
 *    can add runtime checks that get called very early in the daemon startup. Much better to know that your config file (for example) is mangled during the check_environment
 *    call than it is to wait until you've got a running daemon that's trusted to remain error-free and functional.  
 * 
 * @uses PHP 5.3 or Higher
 * @author Shane Harter
 * @singleton
 * @abstract
 */
abstract class Core_Daemon
{
	/**
	 * The config.ini has a setting wherein the daemon can be auto-restarted every X seconds. 
	 * We don't want to kill the server with process spawning so any number below this constant will be ignored. 
	 * @var integer
	 */
	const MIN_RESTART_SECONDS = 10;
	
	/**
	 * The frequency at which the run() loop will run (and execute() method will called). After exectute() is called, any remaining time in that
	 * interval will be spent in a sleep state. If there is no remaining time, that will be logged as an error condition.  
	 * @example $this->loop_interval = 300; // Execute() will be called once every 5 minutes 
	 * @example $this->loop_interval = 0.1; // Execute() will be called 10 times every second 
	 * @var float	The interval in Seconds
	 */
	protected $loop_interval = 0.00;
		
	/**
	 * The frequency (in seconds) at which the timer will automatically restart the daemon. 
	 * @example $this->auto_restart_interval = 3600; // Daemon will be restarted once an hour
	 * @example $this->auto_restart_interval = 86400; // Daemon will be restarted once an day
	 * @var integer		The interval in Seconds
	 */
	protected $auto_restart_interval = 86400;
	
	/**
	 * The email accounts in this list will be notified when a fatal error occurs. 
	 * @var Array
	 */
	protected $email_distribution_list = array();	
	
	/**
	 * A lock provider object that extends the Core_Lock_Lock abstract class.
	 * @var Core_Lock_Lock
	 */
	protected $lock;		
	
	/**
	 * An array of instructions that's displayed when the -i param is passed into the daemon. 
	 * Help's sysadmins and users of your daemons get installation correct. Guide them to set 
	 * correct permissions, crontab entries, init.d scripts, etc
	 * @var Array
	 */
	protected $install_instructions = array();
	
	/**
	 * If the process is forked, this will indicate whether we're still in the parent or not. 
	 * @var boolean
	 */
	protected $is_parent = true;
		
	/**
	 * Timestamp when was this pid started? 
	 * @var integer
	 */
	private $start_time;
	
	/**
	 * Process ID
	 * @var integer
	 */
	private $pid;
	
	/**
	 * An optional filename wherein the PID was written upon init. 
	 * @var string
	 */
	private $pid_file 	= false;
	
	/**
	 * Is this process running as a Daemon? Triggered by the -d param. Has a getter/setter. 
	 * @var boolean
	 */
	private $daemon 	= false;
	
	/**
	 * Has a shutdown signal been recevied? If so, it will shut down upon completion of this iteration. Has a getter/setter. 
	 * @var boolean
	 */
	private $shutdown 	= false;
	
	/**
	 * In verbose mode, every log entry is also dumped to stdout, as long as were not in daemon mode. Has a getter/setter.  
	 * @var boolean
	 */
	private $verbose 	= false;
	
	/**
	 * A simple stack of plugins that are enabled. Set from load_plugin() method.
	 * @var Array
	 */
	private $plugins 	= array();
	
	/**
	 * This has to be set using the Core_Daemon::setFilename before init. 
	 * It's used as part of the auto-restart mechanism. Probably a way to figure it out programatically tho. 
	 * @var string
	 */
	private static $filename = false;
	
	
	/**
	 * The execute method will contain the actual function of the daemon. 
	 * It can be called directly if needed but its intention is to be called every iteration by the ->run() method.
	 * Any exceptions thrown from execute() will be logged as Fatal Errors and result in the daemon attempting to restart or shut down. 
	 * 
	 * @return void
	 * @throws Exception
	 */
	abstract protected function execute();
	
	/**
	 * The setup method will contain the one-time setup needs of the daemon.
	 * It will be called as part of the built-in init() method. 
	 * Any exceptions thrown from setup() will be logged as Fatal Errors and result in the daemon shutting down. 
	 * 
	 * @return void
	 * @throws Exception
	 */
	abstract protected function setup();

	/**
	 * The load plugins method will contain any code required to load any plugins your daemon uses.
	 * It will be called as part of the built-in init() method just after the lock has been satisfied and before the
	 * plugin's setup and daemon's setup are called
	 * @return void
	 */
	abstract protected function load_plugins();
	
	/**
	 * Return a log file name that will be used by the log() method. 
	 * You could hard-code a string like './log', create a simple log rotator using the date() method, etc, etc
	 * 
	 * @return string
	 */
	abstract protected function log_file();	
	
	
    
    /**
     * Return an instance of the Core_Daemon signleton
     * @return Core_Daemon
     */
    public static function getInstance()
    {
    	static $o = false;
    	
    	if ($o)
    		return $o;
    	
    	try
    	{
    		$o = new static;
			$o->load_plugins();
    		$o->check_environment();
    		$o->init();
    	}
    	catch(Exception $e)
    	{
    		$o->fatal_error($e->getMessage());
    	}
    	
    	return $o;
    }

	/**
     * Set the current Filename wherein this object is being instantiated and run. 
     * @param string $filename the acutal filename, pass in __file__
     * @return void
     */
    public static function setFilename($filename)
    {
    	self::$filename = realpath($filename);
    }    

    
    
	protected function __construct()
	{
		// We have to set any installaton instructions before we call getopt()
		$this->install_instructions[] = "Add Crontab Entry:\n   * * * * * " . $this->getFilename();
		
		$this->start_time = time();
		$this->pid = getmypid();
		$this->getopt();
		$this->register_signal_handlers();
		
		// The lock provider is a special plugin
		// We load it outside of the standard load_plugin API, but we need to register it as a plugin
		$this->plugins[] = 'lock';
	}
	
	/**
	 * Ensure that essential runtime conditions are met. 
	 * To easily add rules to this, overload this method, build yourself an array of error messages, 
	 * and then call parent::check_environment($my_errors)
	 * @return void
	 * @throws Exception
	 */
	protected function check_environment(Array $errors = array())
	{
		if (empty(self::$filename))
			$errors[] = 'Filename is Missing: setFilename Must Be Called Before an Instance can be Initialized';
			
		if (empty($this->loop_interval) || is_numeric($this->loop_interval) == false)
			$errors[] = "Invalid Loop Interval: $this->loop_interval";
			
		if (empty($this->auto_restart_interval) || is_numeric($this->auto_restart_interval) == false)
			$errors[] = "Invalid Auto Restart Interval: $this->auto_restart_interval";		
			
		if (is_numeric($this->auto_restart_interval) && $this->auto_restart_interval < self::MIN_RESTART_SECONDS)
			$errors[] = 'Auto Restart Inteval is Too Low. Minimum Value: ' . self::MIN_RESTART_SECONDS;		
			
		if (function_exists('pcntl_fork') == false)
			$errors[] = "The PCNTL Extension is Not Installed";
	
		if (version_compare(PHP_VERSION, '5.3.0') < 0)
			$errors[] = "PHP 5.3 or Higher is Required";
			
		if (is_object($this->lock) == false)
			$errors[] = "You must set a Heatbeat provider";
			
		if (is_object($this->lock) && ($this->lock instanceof Core_Lock_Lock) == false)
			$errors[] = "Invalid Lock Provider: Lock Providers Must Extend Core_Lock_Lock";
			
		if (is_object($this->lock) && ($this->lock instanceof Core_PluginInterface) == false)
			$errors[] = "Invalid Lock Provider: Lock Providers Must Implement Core_PluginInterface";			
			
		// Poll any plugins for errors
		foreach($this->plugins as $plugin) 
			$errors = array_merge($errors, $this->{$plugin}->check_environment());
			
		if (count($errors))
		{
			$errors = implode("\n  ", $errors);
			throw new Exception("Core_Daemon could not begin:\n  $errors");
		}
	}
	
	/**
	 * Check and set the lock provider, Call setup() of any loaded plugins, Call the daemons setup() method.
	 * .
	 * 
	 * @return void
	 */
	private function init()
	{
		// Set the initial lock and gracefully exit if another lock is detected
		if ($lock = $this->lock->check())
		{
			$this->log('Shutting Down: Lock Detected. Details: ' . $lock);
			exit(0);
		}
		$this->lock->set();

		// Setup any registered plugins
		foreach($this->plugins as $plugin)
			$this->{$plugin}->setup();

		// Run the per-daemon setup 
		$this->setup();
		
		// We're all Done. Print some info to the screen and be on our way. 
		if ($this->daemon == false)
			$this->log('Note: Auto-Restart feature is disabled when not run in Daemon mode (using -d).');
			
		$this->log('Process Initialization Complete. Starting timer at a ' . $this->loop_interval . ' second interval.');
	}
	
	public function __destruct() 
	{
		foreach($this->plugins as $plugin)
			$this->{$plugin}->teardown();		
		
		if(!empty($this->pid_file) && file_exists($this->pid_file) && file_get_contents($this->pid_file) == $this->pid)
			unlink($this->pid_file);
    }

	/**
	 * This is the main program loop for the daemon
	 * @return void
	 */
	public function run()
	{
		try
		{		
			while($this->shutdown == false && $this->is_parent)
			{
				$this->timer(true);
				$this->auto_restart();				
				$this->lock->set();
				$this->execute();
				$this->timer();
				
				pcntl_wait($status, WNOHANG);
			}
		}
		catch(Exception $e)
		{
			$this->fatal_error('Error in Core_Daemon::run(): ' . $e->getMessage());
		}			
	}

	
	/**
	 * Parrellize any task by passing it as a callback. Will fork into a child process, execute the callback, and exit. 
	 * If the task uses MySQL or certain other outside resources, the connection will have to be re-established in the child process
	 * so in those cases, set the run_setup flag. 
	 * 
	 * @param Callback $callback		A valid PHP callback. 
	 * @param Array $params				The params that will be passed into the Callback when it's called.
	 * @param unknown_type $run_setup	After the child process is created, it will re-run the setup() method. 
	 * @return boolean					Cannot know if the callback worked or not, but returns true if the fork was successful. 
	 * 
	 * @todo This should accept a closure.
	 */
	public function fork($callback, array $params = array(), $run_setup = false)
	{
		$pid = pcntl_fork();
        switch($pid) 
        {
            case -1:
            	$msg = 'Fork Request Failed. Uncalled Callback: ' . is_array($callback) ? implode('::', $callback) : $callback;
                $this->log($msg, true);
                return false;
                break;
                
            case 0:
				// Child Process
				$this->is_parent = false;
				$this->pid = getmypid();
				
				// Trunc the plugins array, so that way 
				// when this fork dies and the __destruct runs, it will only shut down 
				// plugins that were added to the fork explicitely in the setup() call below
				$this->plugins = array();
				
				if ($run_setup) {
					$this->log("Running Setup in forked PID " . $this->pid);
					$this->setup();
				}
				
				try
				{
					call_user_func_array($callback, $params);
				}
				catch(Exception $e)
				{
					$this->log('Exception Caught from Callback: ' . $e->getMessage());
				}
				
				exit;
            	break;    
                            
            default:
            	// Parent Process
            	return true;
                break;
        }
	}	
	
	/**
	 * Log the $message to the $this->log_file and possibly print to stdout. 
	 * Multi-Line messages will be handled nicely.
	 * @param string $message
	 */
	public function log($message, $send_alert = false)
	{
		static $handle = false;
		static $raise_logfile_error = true;
		
		try
		{
			$header	= "Date                   PID   Message\n";
		        $date 		= date("Y-m-d H:i:s");
			$pid 		= str_pad($this->pid, 5, " ", STR_PAD_LEFT);
			$prefix 	= "[$date] $pid";
	        			
        		if($handle === false)
	        	{
	        		if (strlen($this->log_file()) > 0)
					$handle = @fopen($this->log_file(), 'a+');
	        	
	           		if($handle === false) 
	           	 	{
	            			// If the log file can't be written-to, dump the errors to stdout with the explination... 
	            			if ($raise_logfile_error) {
	            				$raise_logfile_error = false;
	            				$this->log('Unable to write logfile at ' . $this->log_file() . '. Redirecting messages to stdout.');
		            		}
	            	
					throw new Exception("$prefix $message");	                
	        	    	}
	            
	            		fwrite($handle, $header);
				
				if ($this->verbose)
					echo $header;
	        	}
	        
		        $message = $prefix . ' ' . str_replace("\n", "\n$prefix ", trim($message)) . "\n";
        	    	fwrite($handle, $message);
            
           		 if ($this->verbose)
            			echo $message;
		}
	        catch(Exception $e)
		{
        		echo PHP_EOL . $e->getMessage();
        	}
        
		// Optionally distribute this error message to anyboady on the ->email_distribution_list
		if ($send_alert && $message)
        		$this->send_alert($message);
	}
	
	/**
	 * Raise a fatal error and kill-off the process. If it's been running for a while, it'll try to restart itself. 
	 * @param string $log_message
	 */
	public function fatal_error($log_message)
	{
		// Log the Error
		$this->log($log_message);
		$this->log(get_class($this) . ' is Shutting Down...');

		// Send Alerts
		//$this->send_alert($log_message, get_class($this) . ' Shutdown');
		
		// If this process has just started, we have to just log and exit. However, if it was running
		// for a while, we will try to sleep for just a moment in hopes that, if an external resource caused the 
		// error, it'll free itself. Then we try to restart. 
		$delay = 2;
		if (($this->runtime() + $delay) > self::MIN_RESTART_SECONDS)
		{
			sleep($delay);
			$this->restart();
		}
		
		// If we get here, it means we couldn't try a re-start or we tried and it just didn't work. 
		echo PHP_EOL;
		exit(1);
	}

	/**
	 * When a signal is sent to the process it'll be handled here
	 * @param integer $signal
	 * @return void
	 */
    public function signal($signal) 
    {
		switch ($signal)
		{
        	case SIGUSR1:
        		// kill -10 [pid]
        		$this->dump();
        		break;
        	case SIGHUP:
        		// kill -1 [pid]
        		$this->restart();
        		break;
			case SIGUSR2:
			case SIGCONT:
				break;
				
			case SIGINT:
			case SIGTERM:
            	$this->log("Shutdown Signal Received\n");
                $this->shutdown = true;
                break;

			default:
                // handle all other signals
		}
    }	
	
    /**
     * Get the fully qualified command used to start (and restart) the daemon
     * 
     * @return string
     */
    private function getFilename()
    {
    	$command = 'php ' . self::$filename . ' -d';
		
		if ($this->pid_file)
			$command .= ' -p ' . $this->pid_file;
			
		// We have to explicitely redirect output to /dev/null to prevent the exec() call from hanging
		$command .= ' > /dev/null';
		
		return $command;
    }
    
	/**
	 * Register Signal Handlers
	 * @return void
	 */
    private function register_signal_handlers() 
    {
		pcntl_signal(SIGTERM, 	array($this, "signal"));
		pcntl_signal(SIGINT, 	array($this, "signal"));
		pcntl_signal(SIGUSR1, 	array($this, "signal"));
		pcntl_signal(SIGUSR2, 	array($this, "signal"));
		pcntl_signal(SIGCONT, 	array($this, "signal"));
		pcntl_signal(SIGHUP, 	array($this, "signal"));
    }
    
    /**
     * This will dump various runtime details to the log. 
     * @return void
     */
    private function dump()
    {
    	$x = array();
    	$x[] = "Dump Signal Recieved";
    	$x[] = "Loop Interval: " 	. $this->loop_interval;
    	$x[] = "Restart Interval: " . $this->auto_restart_interval;
    	$x[] = "Start Time: " 		. $this->start_time;
    	$x[] = "Duration: " 		. $this->runtime();
    	$x[] = "Log File: " 		. $this->log_file();
    	$x[] = "Daemon Mode: " 		. (int)$this->daemon();
    	$x[] = "Shutdown Signal: " 	. (int)$this->shutdown();
    	$x[] = "Verbose Mode: " 	. (int)$this->verbose();
    	$x[] = "Email On Error: " 	. implode(', ', $this->email_distribution_list);
    	$x[] = "Loaded Plugins: " 	. implode(', ', $this->plugins);
    	$x[] = "Memory Usage: " 	. memory_get_usage(true);
    	$x[] = "Memory Peak Usage: ". memory_get_peak_usage(true);
    	$x[] = "Current User: " 	. get_current_user();
    	$this->log(implode("\n", $x));
    }  	
	
	/**
	 * Send the $message to everybody on the $this->email_distribution_list
	 * @param string $message
	 */
	private function send_alert($message, $subject = false)
	{
		if (empty($message))
			return;
			
		if (empty($subject))
			$subject = get_class($this) . ' Alert';
			
		foreach($this->email_distribution_list as $email)
			@mail($email, $subject, $message);
	}
	
	/**
	 * Time the execution loop and sleep an appropriate amount of time. 
	 * @param boolean $start
	 */
	private function timer($start = false)
	{
		static $start_time = false; 
		
		// Start the Stop Watch and Return
		if ($start)
			return $start_time = microtime(true);

		// End the Stop Watch
		// Calculate the duration. We want to run the code once for every $this->loop_interval. We should sleep for any part of
		// the loop_interval that's left over. If it took longer than the loop_interval, log it and return immediately. 
		if (is_float($start_time) == false)
			$this->fatal_error('An Error Has Occurred: The timer() method Failed. Invalid Start Time: ' . $start_time);
			
		$duration = microtime(true) - $start_time;

		if ($duration > $this->loop_interval) 
		{
			// Even though the execute() method took too long to run, we need to be sure we give the CPU a little break.
			// Sleep for 1/500 a second. 
			usleep(2000);
			$this->log('Run Loop Taking Too Long. Duration: ' . $duration . ' Interval: ' . $this->loop_interval, true);			
			return;
		}
		
		if ($duration > ($this->loop_interval * 0.9))
			$this->log('Warning: Run Loop Near Max Allowed Duration. Duration: ' . $duration . ' Interval: ' . $this->loop_interval, true);
		
		// usleep accepts microseconds, 1 second in microseconds = 1,000,000
		usleep(($this->loop_interval - $duration) * 1000000);
		$start_time = false;
	}
	
	/**
	 * If this is in daemon mode, provide an auto-restart feature. 
	 * This is designed to allow us to get a fresh stack, fresh memory allocation, etc. 
	 * @return boolean
	 */	
	private function auto_restart()
	{
		if ($this->daemon == false)
			return false;
			
		// We have an Auto Kill mechanism to allow the system to restart itself when in daemon mode
		if ($this->runtime() < $this->auto_restart_interval || $this->auto_restart_interval < self::MIN_RESTART_SECONDS)
			return false;	

		$this->restart();
	}
	
	/**
	 * There are 2 paths to the daemon calling restart: The Auto Restart feature, and, also, if a fatal error
	 * is encountered after it's been running for a while, it will attempt to re-start. 
	 * @return void;
	 */
	private function restart()
	{
		if ($this->is_parent == false)
			return false;
					
		$this->log('Restart Happening Now...');
			
		// Then remove the existing lock that we set so the new process doesn't see it and auto-kill itself.
		$this->lock->teardown();
		 
		// Now do the restart and die
		// Close the resource handles to prevent this process from hanging on the exec() output.
		if (is_resource(STDOUT)) fclose(STDOUT);
		if (is_resource(STDERR)) fclose(STDERR); 
		exec($this->getFilename());
		exit();
	}	

    /**
     * Load any plugin that implements the Core_PluginInterface. 
     * All Plugin classes must be named Core_Plugins_ClassNameHere. To select and use a plugin
     * just provide the ClassNameHere part. It will be instantinated as $this->ClassNameHere.  
     *  
     * @param string $class
     * @return void
     * @throws Exception
     */
    protected function load_plugin($class)
    {
    	$qualified_class = ucfirst($class);
		$qualified_class = 'Core_Plugins_' . $qualified_class;
    	
    	if (class_exists($qualified_class, true)) 
    	{
    		$interfaces = class_implements($qualified_class, true);
    		if (is_array($interfaces) && isset($interfaces['Core_PluginInterface'])) {
    			$this->{$class} = new $qualified_class;
    			$this->plugins[] = $class;
    			return;
    		}
    	}
    	
    	throw new Exception(__METHOD__ . ' Failed. Could Not Load Plugin: ' . $class);
    }
    
    /**
     * Handle command line arguments. To easily extend, just add parent::getopt at the TOP of your overloading method. 
     * @return void
     */
	protected function getopt()
	{
        $opts = getopt("Hidvp:");

        if(isset($opts["H"]))
            $this->show_help();
        

        if(isset($opts["i"]))
            $this->show_install_instructions();            
            
        if(isset($opts['d']))
        {			  
        	$pid = pcntl_fork();      	
            if($pid > 0)
				exit();
            
			$this->daemon = true;
			
			$this->pid = getmypid();	// We have a new pid now
			$this->lock->pid = getmypid();
        }
	        
        if(isset($opts['v']) && $this->daemon == false)
			$this->verbose = true;
        
		if(isset($opts['p']))
		{
            $handle = @fopen($opts['p'], 'w');
            if(!$handle)
            	$this->show_help('Unable to write PID to ' . $opts['p']);
            	
			fwrite($handle, $this->pid);
            fclose($handle);

            $this->pid_file = $opts['p'];
        }    
	}

	/**
	 * Print a Help Block and Exit. Optionally Print $msg along with it. 
	 * @param string $msg
	 * @return void
	 */
    protected function show_help($msg = "") {
        
    	if($msg){
            echo "ERROR:\n";
            echo " ".wordwrap($msg, 72, "\n ")."\n\n";
        }
        
        echo get_class($this) . "\n\n";
        echo "USAGE:\n";
        echo " # " . basename(self::$filename) . " -H | -i | [-d] [-v] [-p PID_FILE]\n\n";
        echo "OPTIONS:\n";
        echo " -i Print any daemon install instructions to the screen\n";
        echo " -d Daemon, detach and run in the background\n";
        echo " -v Verbose, echo any logged messages. Ignored in Daemon mode.\n";
        echo " -H Shows this help\n";
        echo " -p PID_FILE File to write process ID out to\n";
        echo "\n";
        exit();
    }
    
    /**
     * Print any install instructions to the screen. 
     * Could be anything from copying init.d scripts, setting crontab entries, creating executable or writable directories, etc. 
     * Add instructions from your daemon by adding them one by one: $this->install_instructions[] = 'Do foo'
     * @return void
     */
    protected function show_install_instructions() {
    	echo get_class($this) . " Installation Instructions:\n\n - ";
    	echo implode("\n - ", $this->install_instructions);
    	echo "\n";
    	exit();
    }
    
	/**
	 * Return the running time in Seconds
	 * @return integer
	 */
	protected function runtime()
	{
		return (time() - $this->start_time);
	}    
    
    /**
     * Combination getter/setter for the $shutdown property. This is needed because $this->shutdown is a private member. 
     * @param boolean $set_value
     */
    protected function shutdown($set_value = null)
    {
    	if ($set_value === false || $set_value === true)
    		$this->shutdown = $set_value;
    		
    	return $this->shutdown;
    }
    
    /**
     * Combination getter/setter for the $daemon property. This is needed because $this->daemon is a private member. 
     * @param boolean $set_value
     */
    protected function daemon($set_value = null)
    {
    	if ($set_value === false || $set_value === true)
    		$this->daemon = $set_value;
    		
    	return $this->daemon;
    }
    
    /**
     * Combination getter/setter for the $verbose property. This is needed because $this->verbose is a private member.  
     * @param boolean $set_value
     */
    protected function verbose($set_value = null)
    {
    	if ($set_value === false || $set_value === true)
    		$this->verbose = $set_value;
    		
    	return $this->verbose;
    }
    
    /**
     * Combination getter/setter for the $pid property. This is needed because $this->pid is a private member.  
     * @param boolean $set_value
     */
    protected function pid($set_value = null)
    {
    	if (is_integer($set_value))
    		$this->pid = $set_value;
    		
    	return $this->pid;
    }   
}
