<?php

class App_ExampleWorkers extends Core_Daemon
{
    protected $loop_interval = 1;
    public $queue;
    public $count;

    public $auction_watcher;

    protected function load_plugins()
    {
        $this->plugin('Lock_File');

        $this->plugin('Plugin_Ini', array(), 'ini');
        $this->ini->filename = BASE_PATH . '/App/config.ini';
        $this->ini->required_sections = array('example_section');
    }

    protected function load_workers()
    {
        $this->worker('example', new App_MyWorker($this));
        $this->example->timeout(30);
        $this->example->workers(3);
    }


    protected function setup()
    {
        $this->count = 0;
        $this->queue = msg_get_queue($this->message_queue, 0777);
        $that = $this;
        if ($this->is_parent())
        {
            $this->on(Core_Daemon::ON_SIGNAL, function($signal) use($that) {

                $array = array(1, true, "beagle!");

                $that->count++;
                if ($signal == SIGBUS) {
                    $that->example->doooit($that->count, $array);
                }
            });

            //I like this idea:
            //$this->mailer->on(self::ERROR, array($this, log_error))
        }
    }


    protected function execute()
    {
        $this->log('Execute..');
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

class AuctionWatcher {

    public $key;
    public function connect() {}
    public function load() {
        return range(1, mt_rand(1,5));
    }

}