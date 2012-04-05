<?php

class App_Example_Workers extends Core_Daemon
{

    protected $loop_interval = 1;



    protected function load_plugins()
    {
        $this->plugin('Lock_File');

        $this->plugin('Plugin_Ini', array(), 'ini');
        $this->ini->filename = 'config.ini';
        $this->ini->required_sections = array('example_section');
    }

    protected function setup()
    {
        if ($this->is_parent)
        {
            $this->worker('ImpressionsHourly', function() {
                echo "WORKER BITCH!";
            });

            $this->ImpressionsHourly->timeout(60);

            $this->ImpressionsHourly->run_setup(true);
        }
    }

    protected function execute()
    {
        $this->ImpressionsHourly();

        if ($this->ImpressionsHourly->state() <> Core_Worker::Core_Worker_Running)
            $this->ImpressionsHourly();	// Or $this->ImpressionsHourly->execute();

        $this->ImpressionsHourly($arg1, $arg2);


        $this->log('The current worker timeout is: ' . $this->ImpressionsHourly->timeout());

        return;

        $example_key = $this->ini['example_section']['example_key'];

        $this->fork($callback, array('Hello from the second fork() call'), true);
    }

    protected function some_forked_task($param)
    {
        $this->log($param);
    }

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
