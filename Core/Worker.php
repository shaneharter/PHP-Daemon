<?php

class Core_Worker
{
	public const Core_Worker_Idle 		= 100;
	public const Core_Worker_Running 	= 200;
	public const Core_Worker_Complete 	= 300;
	public const Core_Worker_Timeout 	= 400;
	public const Core_Worker_Error 		= 500;
	
	/**
	 * How long will the forked process be allowed to run. When time limit is reached, a SIG_KILL will be sent.
	 * @todo Mechanism for a worker to send a signal to the daemon to request more time ala beanstalkd.  
	 * @see $this->timeout($value = false)
	 * @var integer
	 */
	private $timeout = 12;
	
	/**
	 * Should the Daemon setup be re-ran in the forked worker? This should be done, for example, if you need to
	 * use database connections in the worker.
	 * @see $this->run_setup($value = null); 
	 * @var boolean
	 */
	private $run_setup = false;
	
	/**
	 * The callback or closure to be executed in the worker process
	 * @var Callback
	 */
	private $function;
	
	/**
	 * The name of the daemon that created this Worker.
	 * Allows us to grab an instance of it as needed via $daemon_name::getInstance();
	 * @var string
	 */
	private $daemon_name;
	
	/**
	 * The name given to this worker when it was created in the daemon.
	 * @var string
	 */
	private $worker_name;
		
	/**
	 * The state of the underlying worker process
	 * @var integer 	See class constants for description. Enum(100,200,300,400,500).
	 */
	private $state;
	
	/**
	 * The Pid of the forked worker process
	 * @var integer
	 */
	private $pid = false;
	
	/**
	 * Used to differentiate between the parent and the fork after an execute()
	 * @var boolean
	 */
	private $is_parent = true;
	
	
	
	
	public function __construct($function, $worker_name, $daemon_name)
	{
		$this->function 	= $function;
		$this->worker_name 	= $worker_name;
		$this->daemon_name 	= $daemon_name;
	}
	
	
	
	/**
	 * Run "event" for this worker -- this will not execute the worker (see execute() for that), it'll run 
	 * the Worker itself, including timeouts, signal passing, whatever is necessary to maintain the worker. 
	 * @return void
	 */
	public function run()
	{
		
	}
	
	private function daemon()
	{
		return $this->daemon_name::getInstance();
	}
	
	public function execute()
	{
		if ($this->state == Core_Worker::Core_Worker_Running)
			return false;
		
		$pid = pcntl_fork();
        switch($pid) 
        {
            case -1:
                $this->log("{$this->worker_name}::execute() Failed. Could not fork.", true);
                return false;
                break;
                
            case 0:
				// Child Process
				$this->is_parent(false);
				$this->pid(getmypid());
				
				if ($this->run_setup) {
					$this->log('Running Setup in Worker Process');
					
					// Use reflection to invoke the protected run_setup method
					$setup = new ReflectionMethod($this->daemon_name, 'setup');
					$setup->invoke($this->daemon());
				}
				
				try
				{
					call_user_func_array($this->function, func_get_args());
				}
				catch(Exception $e)
				{
					$this->log('Exception Caught from Worker: ' . $e->getMessage());
				}
				
				exit;
            	break;
                            
            default:
            	// Parent Process - Set the pid of the newly forked child process
            	$this->pid = $pid;
            	return true;
                break;
        }		
	}
	
	/**
	 * Log any worker messages to the daemon log file 
	 * @param string $msg
	 * @param boolean $send_alert	Should the log message be sent to the email distribution list?
	 * @see Core_Daemon::log()
	 */
	private function log($msg, $send_alert = false)
	{
		$this->daemon()->log("[{$this->worker_name}] $msg", $send_alert);
	}
	
//	/**
//	 * When a signal is sent to the process it'll be handled here
//	 * @param integer $signal
//	 * @return void
//	 */
//    public function signal($signal) 
//    {
//		switch ($signal)
//		{
//        	case SIGHUP:
//        		// kill -1 [pid]
//        		$this->restart();
//        		break;
//			case SIGUSR2:
//			case SIGCONT:
//				break;
//				
//			case SIGINT:
//			case SIGTERM:
//            	$this->log("Shutdown Signal Received");
//                $this->shutdown = true;
//                break;
//
//			default:
//                // handle all other signals
//		}
//    }	
//	
//	/**
//	 * Register Signal Handlers
//	 * @return void
//	 */
//    private function register_signal_handlers() 
//    {
//		pcntl_signal(SIGTERM, 	array($this, "signal"));
//		pcntl_signal(SIGINT, 	array($this, "signal"));
//		pcntl_signal(SIGUSR1, 	array($this, "signal"));
//		pcntl_signal(SIGUSR2, 	array($this, "signal"));
//		pcntl_signal(SIGCONT, 	array($this, "signal"));
//		pcntl_signal(SIGHUP, 	array($this, "signal"));
//    }
    
    /**
     * Combination getter/setter for the $pid property. 
     * @param boolean $set_value
     */
    protected function pid($set_value = null)
    {
    	if (is_integer($set_value)) {
    		$this->pid = $set_value;
    		$this->daemon()->pid($set_value);
    	}

    	return $this->pid;
    }   
    
    protected function is_parent($set_value = null)
    {
    	if ($set_value === false || $set_value === true) {
    		$this->is_parent = $set_value;
    		$this->daemon()->is_parent($set_value);
    	}
    	
    	return $this->is_parent;
    }    
    	
    public function timeout($set_value = null)
    {
    	if (is_numeric($set_value))
    		$this->timeout = $set_value;
    		
    	return $this->timeout;
    }
    
    public function run_setup($set_value = null)
    {
    	if (is_bool($set_value))
    		$this->run_setup = $set_value;
    		
    	return $this->run_setup;
    }
    
    public function __invoke()
    {
    	return $this->execute();
    }
	
}