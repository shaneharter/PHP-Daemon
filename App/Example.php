<?php

class App_Example extends Core_Daemon
{

	/**
	 * We want to keep the constructor as simple as possible because exceptions thrown from a 
	 * constructor are a PITA and difficult to recover from. Any logic should instead be
	 * put in the setupo method. Instead, use the constructor for runtime settings, etc. 
	 * IMPORTANT to remember to always invoke the parent constructor.  
	 */
	protected function __construct()
	{
		// We want to our daemon to loop once per second.
		$this->loop_interval = 1.00;
				
		// Set our Heatbeat Provider
		$this->lock = new Core_Lock_Null;
		$this->lock->daemon_name = __class__;
		$this->lock->ttl = $this->loop_interval;
        
		// Load our email-on-error queue into the daemon. It will use it when it logs caught/user-defined errors.
		$this->email_distribution_list = email_on_error();
		
		// The main 'config' section is always required, but use this to ensure the config file looks right.
		$this->required_config_sections = array('example_section_one', 'example_section_two');
		
		// You have to use the BASE_PATH, using ./ will fail if the daemon is ever started by crontab. 
        $this->config_file = BASE_PATH . '/config.ini';
        
        // You can set a specific file here or you can implement more advaced filename logic by 
        // overloading the log_file() method like I've done here. 
        $this->log_file	= 'example_log';
        
		parent::__construct();
	}
	
	/**
	 * This is where you implement any once-per-execution setup code. 
	 * @return void
	 * @throws Exception
	 */
	protected function setup()
	{
		
		// Maybe we want to check some config settings, make sure they seem right
		if (false == is_int($this->config['example_section_one']['example_integer']))
			throw new Exception('Setup Failed: Expecting an Integer in example_section_one.example_integer. Value: ' . 
			$this->config['example_section_one']['example_integer']);
		
		// We want to use the auto-retry feature built into our memcache wrapper. This will ensure that micro-second long
		// locks on memcache keys won't cause the daemon to fail. By passing in '1', we're telling it that it should auto-retry
		// every 1/10 second for 10 times before finally failing. You can set it as high as you want. 
		$this->memcache->auto_retry(1);
		
		// You may also want to load any libraries your job needs, make a database connection, etc. 
		// If you have any troubles, throw an exception.
		
		// If you use the fork() method to parallelize long-running tasks, note that if those child processes need a mysql connection
		// and you create the mysql connection here in the setup you'll need to re-run the setup in the child process after the fork. 
		// This is because a Mysql connection in the parent is dead in the child after you fork. The fork() method makes it really simple
		// to do this: just pass "true" as the 3rd param and it will re-run this setup method as soon as the child process is spawned. 
		// However, there may be thinsg in this method that you don't want/need to be called in those cases of re-setup. In those cases
		// you can do this: 
		
		if ($this->is_parent)
		{
			// Some setup stuff you only want run once, in the parent, when the daemon starts. 
		}
	}
	
	/**
	 * This is where you implement your task. This method is called at the frequency
	 * defined by loop_interval. If this method takes longer than 90% of the loop_interval, 
	 * a Warning will be raised. 
	 * 
	 * @return void
	 */
	protected function execute()
	{
		// Any Code Added here will be called Once per Second.
		// If this method takes longer than 0.9 seconds a warning will be sent. If longer than 1 second, an error. 
		// If your usage requires that this be run every second with precision, then you have the responsibility of ensuring
		// that your code here completes its work on time. 
		
		// If you do have a specific long-running task, maybe emailing a bunch of people or using an external API, you should
		// do it in a child-process so it doesn't intefere with your timer. You can do this very, very easily: 
		
		$params 	= array('value_for_param_1', 'value_for_param_2', array('value_for_param_3'));
		$callback 	= array($this, 'some_long_running_task');
		
		if ($this->fork($callback, $params))
		{
			// If we are here, it means the child process was created just fine. However, we have no idea
			// if some_long_running_task() will run successfully. The fork() method returns as soon as the fork is attempted. 
			// If the fork failed for some reason, it will retrun false. 
			
			// NOTE: 
			// If some_long_running_task() requires a MySQL Connection, you need to re-establish the connection in the child process
			// after the fork. If you create the connection here in the setup() method, It will LOOK like the child has a valid MySQL
			// resource after forking, but in reality it's dead. To fix that, you can easily re-run the setup() method in the child
			// process by passing "true" as the 3rd param: 

			$this->fork($callback, $params, true);
			
			// If you have anything in your setup() method that you don't want to ever be re-run from the child process, you can 
			// check the $this->is_parent flag. 
		}
	}
	
	protected function some_long_running_task($param_one, $param_two, Array $param_three)
	{
		
	}
	
	/**
	 * Dynamically build the file name for the log file. This simple algorithm 
	 * will rotate the logs once per day and try to keep them in a central /var/log location. 
	 * @return string
	 */
	protected function log_file()
	{	
		$dir = '/var/log/daemons/example';
		if (@file_exists($dir) == false)
			@mkdir($dir, 0777, true);
		
		if (@is_writable($dir) == false)
			$dir = BASE_PATH . '/example';
		
		return $dir . '/' . $this->log_file . '_' . date('Ymd');
	}
}
