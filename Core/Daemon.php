<?php 

declare(ticks = 5);

/**
 * Daemon Base Class with various cool daemon features. Unlike other Daemon libraries, this includes a built-in timer. 
 * You define an execute() method, and a loop_interval. Interval could be as low as 5, 10 times per second. The timer does the rest. 
 * Long-running tasks can be passed as a callback into the ->fork() method to be run in a child process. 
 * 
 * USAGE: 
 * 1. Implement the setup() and execute() methods in your base class. 
 * 	  - setup() is called once, during the Init process. It's called after the daemon init is completed.  
 *    - execute() is called inside the run() loop. Like an internal crontab -- execute() runs at whatever frequency you define. 
 * 
 * 2. In your constructor, CALL THE parent::__construct() and then set: 
 * 		lock						Currently only a Memcache provider is available. Each provider will have its own requirements.
 * 		loop_interval				In seconds, how often should the execute() method run? Decimals are allowed. Tested as low as 0.10.   
 * 		email_distribution_list		An array of email addresses that will be alerted when things go bad. 
 * 		log_file					The name of the file you want to log to. Can alternatively implement the log_file() method. See phpdocs.  
 * 		required_config_sections 	The setup process will validate that the config.ini has the sections you add here. The "config" section is mandatory.
 * 
 * 3. Create a config file in ./config.ini, or elsewhere if you set ->config_file in your constructor. 
 * 	  Copy this into the top of it and then add whatever you'd like. 
 * 
 * 		[config]
 * 		auto_restart_interval = 600
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
	 * The contents of the config.ini
	 * @var array
	 */
	public $config = array();
	
	/**
	 * A lock provider object that implements the Core_Lock_LockInterface interface.
	 * @var Core_Lock_LockInterface
	 */
	protected $lock;
		
	/**
	 * This is the config file accessed by self::__construct
	 * @var string
	 */
	protected $config_file = './config.ini';
	
	/**
	 * The email accounts in this list will be notified when a fatal error occurs. 
	 * @var Array
	 */
	protected $required_config_sections = array('config');	
	
	/**
	 * The email accounts in this list will be notified when a fatal error occurs. 
	 * @var Array
	 */
	protected $email_distribution_list = array();
	
	/**
	 * The frequency at which the run() loop will run (and execute() method will called). After exectute() is called, any remaining time in that
	 * interval will be spent in a sleep state. If there is no remaining time, that will be logged as an error condition.  
	 * @example $this->loop_interval = 300; // Execute() will be called once every 5 minutes 
	 * @example $this->loop_interval = 0.1; // Execute() will be called 10 times every second 
	 * @var float	The time in Seconds
	 */
	protected $loop_interval = 0.00;
	
	/**
	 * A mandatory filename where we write logs to. 
	 * @var string
	 */
	protected $log_file = './log';
	
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
	 * This has to be set using the Core_Daemon::setFilename before init. 
	 * It's used as part of the auto-restart mechanism. Probably a way to figure it out programatically tho. 
	 * @var string
	 */
	private static $filename = false;
	
	/**
	 * This has to be set using the Core_Daemon::setDaemonName before init. 
	 * It's used as part of the lock mechanism. 
	 * @var string
	 */
	private static $daemon_name = false;	
	
	protected function __construct()
	{
		$this->start_time = time();
		$this->config = parse_ini_file($this->config_file, true);
		$this->pid = getmypid();
		$this->getopt();
		$this->register_signal_handlers();
	}
	
	/**
	 * Ensure that essential runtime conditions are met. 
	 * @return void
	 * @throws Exception
	 */
	protected function check_environment()
	{
		$errors = array();
		
		if (empty(self::$filename))
			$errors[] = 'Filename is Missing: setFilename Must Be Called Before an Instance can be Initialized';
			
		if (empty(self::$daemon_name))
			$errors[] = 'Daemon Name is Missing: setDaemonName Must Be Called Before an Instance can be Initialized';		
		
		if (empty($this->loop_interval) || is_numeric($this->loop_interval) == false)
			$errors[] = "Invalid Loop Interval: $this->loop_interval";
			
		if (function_exists('pcntl_fork') == false)
			$errors[] = "The PCNTL Extension is Not Installed";
	
		if (version_compare(PHP_VERSION, '5.3.0') < 0)
			$errors[] = "PHP 5.3 or Higher is Required";
			
		if (is_object($this->lock) == false)
			$errors[] = "You must set a Heatbeat provider";
			
		if (is_object($this->lock) && ($this->lock instanceof Core_Lock_LockInterface) == false)
			$errors[] = "Invalid Lock Provider: Lock Providers Must Implement Core_Lock_LockInterface";
			
		if (is_object($this->lock) && ($this->lock instanceof Core_ResourceInterface) == false)
			$errors[] = "Invalid Lock Provider: Lock Providers Must Implement Core_ResourceInterface";			
			
		// Check any Lock errors -- implements Core_ResourceInterface
		$errors = array_merge($errors, $this->lock->check_environment());
			
		if (count($errors))
		{
			$errors = implode("\n", $errors);
			throw new Exception("Core_Daemon::check_environment Found The Following Errors:\n$errors");
		}
	}
	
	/**
	 * Initialize external resources. Done here to simplfy the constructor
	 * and because error handling and exception throwing from within the constructor causes drama. 
	 * 
	 * @return void
	 */
	private function init()
	{
		global $config;
		
		// Validate that we have proper-looking config
		$missing_sections = array();
		$this->required_config_sections = array_merge(array('config'), $this->required_config_sections);
		foreach($this->required_config_sections as $section)
			if (is_array($this->config[$section]) && count($this->config[$section]) > 0)
				continue;
			else
				$missing_sections[] = $section;
				
		if (count($missing_sections))
			throw new Exception('Core_Daemon::init failed: Seems the config file is missing some important sections: ' . implode(',', $missing_sections));
			
		if ($this->config['config']['auto_restart_interval'] < self::MIN_RESTART_SECONDS)
			throw new Exception('Core_Daemon::init failed: Auto Restart Inteval set in config file is too low. Minimum Value: ' . self::MIN_RESTART_SECONDS);
			
		// Setup the Lock Provider
		$this->lock->setup();
			
		// Set the initial lock and gracefully exit if another lock is detected
		$lock = $this->lock->check();
		
		if ($lock)
		{
			$this->log('Shutting Down: Lock Detected. Details: ' . $lock);
			exit(0);
		}

		$this->lock->set();
		
		// Run any per-daemon setup 
		$this->setup();
		
		// We're all Done. Print some info to the screen and be on our way. 
		if ($this->daemon == false)
			$this->log('Note: Auto-Restart Feature Disabled When Not Run as Daemon');
			
		$this->log('Process Initialization Complete. Ready to Begin.');		

	}
	
	public function __destruct() 
	{
		$this->lock->teardown();
		
		if(!empty($this->pid_file) && file_exists($this->pid_file))
        	unlink($this->pid_file);
    }
    
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

	/**
     * Give this Daemon instance a unique name
     * @param string $name
     * @return void
     */
    public static function setDaemonName($name)
    {
    	self::$daemon_name = $name;
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
	 * The execute method will contain the actual function of the daemon. 
	 * It can be called directly if needed but its intention is to be called every iteration by the ->run() method.
	 * Any exceptions thrown from execute() will be logged as Fatal Errors and result in the daemon attempting to restart or shut down. 
	 * 
	 * @return void
	 * @throws Exception
	 */
	protected abstract function execute();
	
	/**
	 * The setup method will contain the one-time setup needs of the daemon.
	 * It will be called as part of the built-in init() method. 
	 * Any exceptions thrown from setup() will be logged as Fatal Errors and result in the daemon shutting down. 
	 * 
	 * @return void
	 * @throws Exception
	 */
	protected abstract function setup();

    /**
     * Extend this method if you want to use an algorithm to determine the logfile name (daily rotation, etc)
     * This is not a combo getter/setter because it is not needed: $this->log_file is a protected member. 
     * @retun string Returns, untouched, the value of $this->log_file.  
     */
    protected function log_file()
    {
    	return $this->log_file;
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
	protected function auto_restart()
	{
		if ($this->daemon == false)
			return false;
			
		// We have an Auto Kill mechanism to allow the system to restart itself when in daemon mode
		if ($this->runtime() < $this->config['config']['auto_restart_interval'] || $this->config['config']['auto_restart_interval'] < self::MIN_RESTART_SECONDS)
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
			
		// Build the QS to execute
		$command = 'php ' . self::$filename . ' -d';
		
		if ($this->pid_file)
			$command .= ' -p ' . $this->pid_file;
			
		// We have to explicitely redirect output to /dev/null to prevent the exec() call from hanging
		$command .= ' > /dev/null';
		
		// Then remove the existing lock that we set so the new process doesn't see it and auto-kill itself.
		$this->lock->teardown();
		 
		// Now do the restart and die
		// Close the resource handles to prevent this process from hanging on the exec() output.
		if (is_resource(STDOUT)) fclose(STDOUT);
		if (is_resource(STDERR)) fclose(STDERR); 
		exec($command);
		exit();
	}
	
	/**
	 * Return the running time in Seconds
	 * @return integer
	 */
	public function runtime()
	{
		return (time() - $this->start_time);
	}
	
	/**
	 * Log the $message to the $this->log_file and possibly print to stdout. 
	 * Multi-Line messages will be handled nicely.
	 * @param string $message
	 */
	public function log($message, $send_alert = false)
	{
		static $handle = false;
		
		try
		{
			$header		= "Date                  PID   Message\n";
	        $date 		= date("Y-m-d H:i:s");
	        $pid 		= str_pad($this->pid, 5, " ", STR_PAD_LEFT);
	        $prefix 	= "[$date] $pid";
	        			
        	if($handle === false)
	        {
	        	if (strlen($this->log_file()) == 0)
	        		throw new Exception("$prefix $message");
	        	
				$handle = @fopen($this->log_file(), 'a+');
	        	
	            if($handle)
	                fwrite($handle, $header);
	            else
					throw new Exception("$prefix $message");
				
				if ($this->verbose)
					echo $header;
	        }
	        
	        $message = $prefix . ' ' . str_replace("\n", "\n$prefix ", trim($message)) . "\n";
            fwrite($handle, $message);
            
            if ($this->verbose)
            	throw new Exception($message);
		}
        catch(Exception $e)
        {
        	echo $e->getMessage();
        }
        
        // Optionally distribute this error message to anyboady on the ->email_distribution_list
        if ($send_alert && $message)
        	$this->send_alert($message);
	}
	
	/**
	 * Raise a fatal error and kill-off the process. If it's been running for a while, it'll try to restart itself. 
	 * @param string $log_message
	 */
	protected function fatal_error($log_message)
	{
		// Log the Error
		$this->log($log_message);
		$this->log(get_class($this) . ' is Shutting Down...');

		// Send Alerts
		$this->send_alert($log_message, get_class($this) . ' Shutdown');
		
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
		exit(1);
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
	protected function fork($callback, array $params = array(), $run_setup = false)
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
				
				if ($run_setup) {
					$this->log("running Setup in PID " . $this->pid);
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
            	$this->log("Shutdown Signal Received");
                $this->shutdown = true;
                break;

			default:
                // handle all other signals
		}
    }
    
    /**
     * Handle command line arguments. To easily extend, just add parent::getopt at the TOP of your overloading method. 
     * @return void
     */
	protected function getopt()
	{
        $opts = getopt("Hdvp:");

        if(isset($opts["H"]))
            $this->show_help();
        
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
        echo " # " . basename(self::$filename) . " -H | [-d] [-v] [-p PID_FILE]\n\n";
        echo "OPTIONS:\n";
        echo " -d Daemon, detach and run in the background\n";
        echo " -v Verbose, echo any logged messages. Ignored in Daemon mode.\n";
        echo " -H Shows this help\n";
        echo " -p PID_FILE File to write process ID out to\n";
        echo "\n";
        exit();
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
    	$x[] = "Start Time: " 		. $this->start_time;
    	$x[] = "Duration: " 		. $this->runtime();
    	$x[] = "Config File: " 		. $this->config_file;
    	$x[] = "Log File: " 		. $this->log_file();
    	$x[] = "Daemon Mode: " 		. (int)$this->daemon();
    	$x[] = "Shutdown Signal: " 	. (int)$this->shutdown();
    	$x[] = "Verbose Mode: " 	. (int)$this->verbose();
    	$x[] = "Restart Interval: " . $this->config['config']['auto_restart_interval'];
    	$x[] = "Memory Usage: " 	. memory_get_usage(true);
    	$x[] = "Memory Peak Usage: ". memory_get_peak_usage(true);
    	$x[] = "Current User: " 	. get_current_user();
    	
    	$this->log(implode("\n", $x));
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