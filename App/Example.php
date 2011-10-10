<?php

class App_Example extends Core_Daemon
{

	/**
	 * We keep the constructor as simple as possible because exceptions thrown from a 
	 * constructor are a PITA and difficult to recover from. 
	 * 
	 * Use the constructor only to set runtime settings, anything else you need to prepare your 
	 * daemon should should go in the setup() method. 
	 * 
	 * Any Plugins should be loaded in the setup() method.
	 * 
	 * IMPORTANT to remember to always invoke the parent constructor.  
	 */
	protected function __construct()
	{
		// We want to our daemon to loop once every 5 seconds.
		$this->loop_interval = 5.00;
				
		// Set our Lock Provider
		$this->lock = new Core_Lock_File;
		$this->lock->daemon_name = __CLASS__;
		$this->lock->ttl = $this->loop_interval;
        
		// Load our email-on-error queue into the daemon. It will use it when it logs caught/user-defined errors.
		$this->email_distribution_list = email_on_error();
		
		parent::__construct();
	}

	protected function load_plugins()
	{
		// Use the INI plugin to provide an easy way to include config settings
		$this->load_plugin('Ini');
		$this->Ini->filename = 'config.ini';
		$this->Ini->required_sections = array('example_section');
	}
	
	/**
	 * This is where you implement any once-per-execution setup code. 
	 * @return void
	 * @throws Exception
	 */
	protected function setup()
	{
		// Use setup() to load any libraries your job needs, make a database connection, etc. 
		// If you have any troubles, throw an exception.
		
		// If you use the fork() method to parallelize tasks, note that fork() includes an optional third true/false paramter 
		// used to have the daemon re-run this setup() method after the fork. You should do this if you want to setup resources here
		// that will then be available in the child process. 
			
		// Some setup stuff you only want run once, in the parent, when the daemon starts. You can use ->is_parent for that: 
		
		if ($this->is_parent)
		{
		}
	}
	
	/**
	 * This is where you implement the tasks you want your daemon to perform. 
	 * This method is called at the frequency defined by loop_interval. 
	 * If this method takes longer than 90% of the loop_interval, a Warning will be raised. 
	 * 
	 * @return void
	 */
	protected function execute()
	{
                // The Ini plugin implements the SPL ArrayAccess interface, so in your execute() method you can access the data like this:
                $example_key = $this->Ini['example_section']['example_key'];
                $this->log("Reading value from INI file using the Ini plugin: $example_key");

		$this->log('Executing this stuff every ' . $this->loop_interval . ' seconds. The next 2 messages will be coming from forked children that will exit once they log their message.');

		// If you do have a specific long-running task, maybe emailing a bunch of people or using an external API, you can
		// easily fork a child process: 
		$callback = array($this, 'some_forked_task');
		
		if ($this->fork($callback, array('Hello from the first fork() call. Notice my unique PID?')))
		{
			// If we are here, it means the child process was created just fine. However, we have no idea
			// if some_long_running_task() will run successfully. The fork() method returns as soon as the fork is attempted. 
			// If the fork failed for some reason, it will retrun false. 
		}
		
		// NOTE: 
		// If yoru forked process requires a MySQL Connection, you need to re-establish the connection in the child process
		// after the fork. If you create the connection here in the setup() method, It will LOOK like the child has a valid MySQL
		// resource after forking, but in reality it's dead. To fix that, you can easily re-run the setup() method in the child
		// process by passing "true" as the 3rd param: 
		$this->fork($callback, array('Hello from the second fork() call. Notice MY unique PID?'), true);
	}
	
	protected function some_forked_task($param)
	{
		$this->log($param);
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
			$dir = BASE_PATH . '/example_logs';
		
		return $dir . '/log_' . date('Ymd');
	}
}
