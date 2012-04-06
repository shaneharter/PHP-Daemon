<?php

class App_ExampleWorkers extends Core_Daemon
{
    protected $loop_interval = 1;

    protected function load_plugins()
    {
        $this->plugin('Lock_File');

        $this->plugin('Plugin_Ini', array(), 'ini');
        $this->ini->filename = BASE_PATH . '/App/config.ini';
        $this->ini->required_sections = array('example_section');
    }

    protected function setup()
    {
        if ($this->is_parent)
        {

        }
    }

    protected function execute()
    {
        $this->log('Look, Ma! I did it.');
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
