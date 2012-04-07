<?php

class App_ExampleWorkers extends Core_Daemon
{
    protected $loop_interval = 5;
    public $message_queue = 90210124545;
    public $queue;

    protected function load_plugins()
    {
        $this->plugin('Lock_File');

        $this->plugin('Plugin_Ini', array(), 'ini');
        $this->ini->filename = BASE_PATH . '/App/config.ini';
        $this->ini->required_sections = array('example_section');
    }

    protected function setup()
    {
        $this->queue = msg_get_queue($this->message_queue, 0777);

        if ($this->is_parent())
        {
            $that = $this;

            $this->on(Core_Daemon::ON_SIGNAL, function($signal) use($that) {
                $err = null;
                $that->queue = msg_get_queue($that->message_queue, 0777);
                if ($signal == SIGUSR2) {
                    if (msg_send($that->queue, 1, "Hello, from SIGUSR2 in " . $that->pid(), true, true, $err))
                        $that->log('Message Enqueued for Child');
                    else {
                        $that->log('Shitcock! Message Not Enqueued! Error: ' . $err);
                    }
                }
            });

            $this->on(Core_Daemon::ON_SIGNAL, function($signal) use($that) {
                if ($signal == SIGIO) {
                    $that->log('Starting Up a Child...');
                    $that->fork(array($that, 'child'), array(), true);
                }
            });



            if (msg_send($that->queue, 1, "Hello, from setup() in " . $this->pid(), true, true, $err))
                $that->log('Message Enqueued for Child');
            else {
                $that->log('Shitcock! Message Not Enqueued! Error: ' . $err);

            }
        }
    }

    protected function execute()
    {
        $this->log('Hola');
    }

    protected function child()
    {
        $this->log('here i iz');
        $message = '';
        $message_type = null;
        if (msg_receive($this->queue, 1, $message_type, 1024000, $message)) {
            $this->log("Message from the future received: " . $message);
            $this->log("That was it. Amazing, right?");
        } else {
            $this->log('Shitballs! Nothing But Static');
        }
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
