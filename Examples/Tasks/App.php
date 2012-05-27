<?php

class Examples_Tasks_App extends Core_Daemon
{
    protected  $loop_interval = 1;

	protected function load_plugins()
	{
        // Set our Lock Provider
        $this->plugin('Lock_File');
	}
	
	/**
	 * This is where you implement any once-per-execution setup code. 
	 * @return void
	 * @throws Exception
	 */
	protected function setup()
	{

	}
	
	/**
	 * This is where you implement the tasks you want your daemon to perform. 
	 * This method is called at the frequency defined by loop_interval. 
	 *
	 * @return void
	 */
	protected function execute()
	{
        static $i = 0;
        $i++;

        if ($i == 15) {
            $this->log("Creating Sleepy Task");
            $this->task(array($this, 'task_sleep'));
        }

        if ($i == 20) {
            $this->log("Shutting Down..");
        }
	}

	protected function task_sleep()
	{
		$this->log("Sleeping For 20 Seconds");
        sleep(20);
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
