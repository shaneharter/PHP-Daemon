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
		// Load our email-on-error queue into the daemon. It will use it when it logs caught/user-defined errors.
		$this->email_distribution_list = email_on_error();
		
		// The main 'config' section is always required, but use this to ensure the config file looks right.
		$this->required_config_sections = array('example_section_one', 'example_section_two');
		
		// We want to our daemon to loop once per second.
		$this->loop_interval = 1.00;
		
		// You have to use the BASE_PATH, using ./ will fail if the daemon is ever started by crontab. 
        $this->config_file = BASE_PATH . '/example/config.ini';
        
        // You can set a specific file here or you can implement more advaced filename logic by 
        // overloading the log_file() method like I've done here. 
        $this->log_file	= 'example_log';
        
        // You probably will keep the memcache settings in a single config location in your app
        // that you'd pull from here, but 
		$this->memcache_servers[] = array('host' => '127.0.0.1', 'port' => '11211');
        
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
	}
	
	/**
	 * This is where you implement your task. This method is called at the frequency
	 * defined by loop_interval. 
	 * @return void
	 */
	protected function execute()
	{
		// Any Code Added here will be called Once per Second.
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
