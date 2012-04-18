<?php

class Example_App extends Core_Daemon
{
    protected  $loop_interval = 2;

	protected function load_plugins()
	{
        // Set our Lock Provider
        $this->plugin('Lock_File');

		// Use the INI plugin to provide an easy way to include config settings
        // If the plugins are located in the /Core/Plugins directory, the name doesn't have to be qualified.
        // In this case you can refer to the Core_Plugins_Ini class as Core_Plugins_Ini, Plugins_Ini, or just Ini
		$this->plugin('ini');
		$this->ini->filename = BASE_PATH . '/Example/config.ini';
		$this->ini->required_sections = array('example_section');

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
		
		// If you use the fork() method to parallelize tasks, note that fork() includes an optional third true/false parameter
		// used to have the daemon re-run this setup() method after the fork. You should do this if you want to setup resources here
		// that will then be available in the child process. 
			
		// Some setup stuff you only want run once, in the parent, when the daemon starts. You can use ->is_parent for that: 
		
		if ($this->is_parent())
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
		$example_key = $this->ini['example_section']['example_key'];
					
		$this->log($example_key);

		// If you do have a specific long-running task, maybe emailing a bunch of people or using an external API, you can
		// easily fork a child process: 
		$callback = array($this, 'some_forked_task');

		if ($this->fork($callback, array('Hello from the first fork() call')))
		{
			// If we are here, it means the child process was created just fine. However, we have no idea
			// if some_long_running_task() will run successfully. The fork() method returns as soon as the fork is attempted. 
			// If the fork itself failed for some reason, it will return false.
		}

		// If your forked process requires a MySQL Connection, you need to re-establish the connection in the child process
		// after the fork. If you create the connection here in the setup() method, It will LOOK like the child has a valid MySQL
		// resource after forking, but in reality it's dead. To fix that, you can easily re-run the setup() method in the child
		// process by passing "true" as the 3rd param: 
		$this->fork($callback, array('Hello from the second fork() call'), true);

        // Note that the often the output from the second fork call is logged before the first. The processes execution
        // is entirely dependant on the kernel scheduler.
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
