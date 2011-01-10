<?php 

declare(ticks = 5);

/**
 * Daemon Base Class with various cool daemon features. 
 * 
 * USAGE: 
 * 1. Implement the setup() and execute() methods in your base class. 
 * 	  - setup() is called once, during the Init process. It's called after the daemon init is completed.  
 *    - execute() is called inside the run() loop. 
 * 
 * 2. In your constructor, CALL THE parent::__construct() and then set: loop_interval, email_distribution_list, log_file, required_config_sections. See phpdoc for each. 
 * 
 * 3. Set the PREFIX define, this is your memcache namespace. 
 * 
 * 4. Create a config file in ./config.ini, or elsewhere if you set ->config_file in your constructor. Copy this into the top of it and then add whatever you'd like. 
 * 
 * 		[config]
 * 		auto_restart_interval = 600
 * 
 * 5. Overload the log_file() method if you wish to use an algorithm to define the log file. 
 * 
 * @uses PHP 5.3 or Higher
 * @uses Core_Memcache
 * @author Shane Harter
 * @singleton
 * @abstract
 */
abstract class Core_Daemon
{
	const CACHE_HEARTBEAT_SECONDS = 2;
	const CACHE_HEARTBEAT_KEY = 'process_heartbeat';
	
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
	 * This is the Core_Memcache proxy object
	 * @var Core_Memcache
	 */
	public $memcache;
	
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
	 * An array containing an associative array of Memcache servers with the keys: host, port. 
	 * @var string
	 */
	protected $memcache_servers = array();	
	
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
	 * @var unknown_type
	 */
	private static $filename = false;
		
	
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
		
		if (empty($this->loop_interval) || is_numeric($this->loop_interval) == false)
			$errors[] = "Invalid Loop Interval: $this->loop_interval";
			
		if (function_exists('pcntl_fork') == false)
			$errors[] = "The PCNTL Extension is Not Installed";
			
		if (defined('PREFIX') == false)
			$errors[] = "The PREFIX constant is Not Defined";
			
		if (version_compare(PHP_VERSION, '5.3.0') < 0)
			$errors[] = "PHP 5.3 or Higher is Required";
			
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
		
		if (self::$filename == false)
			throw new Exception('Core_Daemon::init failed: setFilename Must Be Called Before an Instance can be Initialized');
		
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
			
		// Connect to memcache
		$this->memcache = new Core_Memcache();
		if ($this->memcache->connect_all($this->memcache_servers) === false)
			throw new Exception('Core_Daemon::init failed: Memcache Connection Failed');

		// Set the initial heartbeat and gracefully exit if another heartbeat is detected
		try
		{
			$this->heartbeat();
		}
		catch(Exception $e)
		{
			$this->log('Shutting Down... ' . $e->getMessage());
			exit(0);
		}
		
		// Run any per-daemon setup 
		$this->setup();
		
		// We're all Done. Print some info to the screen and be on our way. 
		if ($this->daemon == false)
			$this->log('Note: Auto-Restart Feature Disabled When Not Run as Daemon');
			
		$this->log('Process Initialization Complete. Ready to Begin.');		

	}
	
	public function __destruct() 
	{
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
     * @param $filename the acutal filename, pass in __file__
     * @return void
     */
    public static function setFilename($filename)
    {
    	self::$filename = realpath($filename);
    }    
    
    /**
     * This is the main program loop for the daemon
     * @return void
     */
	public function run()
	{
		try
		{		
			while($this->shutdown == false)
			{
				$this->timer(true);
				$this->auto_restart();				
				$this->heartbeat();
				$this->execute();
				$this->timer();
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

		if ($duration > ($this->loop_interval * 0.9))
			$this->log('Run Loop Taking Too Long. Duration: ' . $duration . ' Interval: ' . $this->loop_interval);
		
		if ($duration > $this->loop_interval)
			return;
		
		// usleep accepts microseconds, 1 second in microseconds = 1,000,000
		usleep(($this->loop_interval - $duration) * 1000000);
		$start_time = false;
	}
	
	/**
	 * Set a memcache key heartbeat. Also used as a process lock: Cron will attempt to start a Bot every minute but it will 
	 * automatically kill itself if the heartbeat of a running bot is detected 
	 * 
	 * @return void
	 */
	protected function heartbeat()
	{
		$heartbeat = $this->memcache->get(PREFIX . self::CACHE_HEARTBEAT_KEY);
		
		if ($heartbeat && $heartbeat['pid'] != $this->pid)
			throw new Exception(sprintf('Additional Heartbeat Detected. [Heartbeat Pid: %s Set At: %s] [Current Pid: %s At: %s]', 
				$heartbeat['pid'], $heartbeat['timestamp'], $this->pid, time()));

		$heartbeat = array();
		$heartbeat['pid'] = $this->pid;
		$heartbeat['timestamp'] = time();
				
		$this->memcache->set(PREFIX . self::CACHE_HEARTBEAT_KEY, $heartbeat, false, ceil($this->loop_interval) + self::CACHE_HEARTBEAT_SECONDS);
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
		$this->log('Restart Happening Now...');
			
		// Build the QS to execute
		$command = 'php ' . self::$filename . ' -d';
		
		if ($this->pid_file)
			$command .= ' -p ' . $this->pid_file;
			
		// We have to explicitely redirect output to /dev/null to prevent the exec() call from hanging
		$command .= ' > /dev/null';
		
		// Then remove the existing heartbeat that we set so the new process doesn't see it and auto-kill itself.
		$this->memcache->delete(PREFIX . self::CACHE_HEARTBEAT_KEY);
		 
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
	public function log($message)
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
	        
	        $message = $prefix . ' ' . str_replace("\n", "\n$prefix ", trim($message))."\n";
            fwrite($handle, $message);
            
            if ($this->verbose)
            	throw new Exception($message);
		}
        catch(Exception $e)
        {
        	echo $e->getMessage();
        }
	}
	
	/**
	 * Raise a fatal error and kill-off the process. If it's been running for a while, it'll try to restart itself. 
	 * @param string $log_message
	 */
	protected function fatal_error($log_message)
	{
		$this->log($log_message);
		$this->log(get_class($this) . ' is Shutting Down...');
		
		foreach($this->email_distribution_list as $email)
			mail($email, get_class($this) . ' Shutdown', $log_message);
		
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
        {
            $this->show_help();
        }
        
        if(isset($opts['d']))
        {			  
        	$pid = pcntl_fork();      	
            if($pid > 0) {
				exit();
            }
            
			$this->daemon = true;
			$this->pid = getmypid();	// We have a new pid now
        }
	        
        if(isset($opts['v']) && $this->daemon == false)
        {
			$this->verbose = true;
        }        
        
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