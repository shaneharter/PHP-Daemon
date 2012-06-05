<?php

class Poller extends Core_Daemon
{
    protected $loop_interval = 3;

    /**
     * This will hold the results returned by our API
     * @var array
     */
    protected $results = array();

    /**
     * Create a Lock File plugin to ensure we're not running duplicate processes, and load
     * the config file with all of our API connection details
     */
	protected function setup_plugins()
	{
        $this->plugin('Lock_File');

		$this->plugin('ini');
		$this->ini->filename = BASE_PATH . '/config.ini';
		$this->ini->required_sections = array('api');
	}

    protected function setup_workers()
    {
        $this->worker('api', new API);
        $this->api->workers(1);

        $this->api->timeout(120);
        $this->api->onTimeout(function($call, $log) {
            $log("API Timeout Reached");
        });

        $that = $this;
        $this->api->onReturn(function($call, $log) use($that) {
            if ($call->method == 'poll') {
                $that->set_results($call->return);
                $log("API Results Updated...");
            }
        });

    }

	/**
	 * The setup method is called only in your parent daemon class, after plugin_setup and worker_setup and before execute()
	 * @return void
	 * @throws Exception
	 */
	protected function setup()
	{
        // We don't need any additional setup.
        // Implement an empty method to satisfy the abstract base class
	}
	
	/**
	 * This daemon will perform a continuous long-poll request against an API. When the API returns, we'll update
     * our $results array, then start the next polling request. There will always be a background worker polling for
     * updated results.
	 * @return void
	 */
	protected function execute()
	{
        if (!$this->api->is_idle()) {
            $this->log("Event Loop Iteration: API Call running in the background worker process.");
            return;
        }

        // If the Worker is idle, it means it just returned our stats.
        // Log them and start another request

        $this->log("Current Sales:   " . $this->results['customers']);
        $this->log("Current Sales Amount: $ " . number_format($this->results['sales'], 2));

        $this->api->poll();
	}

    public function set_results(Array $results) {
        $this->results = $results;
    }
	
	/**
	 * Dynamically build the file name for the log file. This simple algorithm 
	 * will rotate the logs once per day and try to keep them in a central /var/log location. 
	 * @return string
	 */
	protected function log_file()
	{	
		$dir = '/var/log/daemons/longpoll';
		if (@file_exists($dir) == false)
			@mkdir($dir, 0777, true);
		
		if (@is_writable($dir) == false)
			$dir = BASE_PATH . '/logs';
		
		return $dir . '/log_' . date('Ymd');
	}
}
