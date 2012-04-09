<?php

class App_ExampleWorkers extends Core_Daemon
{
    protected $loop_interval = .5;
    public $message_queue = 90210124545;
    public $queue;

    public $auction_watcher;

    protected function load_plugins()
    {
        $this->plugin('Lock_File');

        $this->plugin('Plugin_Ini', array(), 'ini');
        $this->ini->filename = BASE_PATH . '/App/config.ini';
        $this->ini->required_sections = array('example_section');
    }

    protected function worker(){}

    protected function setup()
    {
        $this->queue = msg_get_queue($this->message_queue, 0777);
        $that = $this;
        if ($this->is_parent())
        {
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

            $this->auction_watcher = new AuctionWatcher();
            $this->auction_watcher->key = $this->ini['example_section']['example_key'];
            $this->auction_watcher->connect();

            $this->worker('MailChimp', false);

            $this->worker('Watcher', new AuctionWatcher);



            $this->workerClass('MailChimp');

            $this->workerFunction('mailer');
            $this->mailer->function = array($this, 'mailer');
            $this->mailer->timeout = 30;
            $this->mailer->on(self::ON_ERROR, array($this, 'mailer_error'));
            $this->mailer->on(self::ON_SHUTDOWN, function() use($that) {
                $that->log('Mailer Worker Shutting Down...');
            });

            // ...

            $this->mailer()
                 ->on(self::ERROR, array($this, log_error))
                 ->on();



            // When you call that, it should actually call the mediator. Which will push work in the queue

            // or
            $this->workers->mailer();


        }
    }




    protected function execute()
    {
        foreach($this->auction_watcher->load() as $item) {

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

class AuctionWatcher {

    public $key;
    public function connect() {}
    public function load() {
        return range(1, mt_rand(1,5));
    }

}