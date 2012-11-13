<?php

namespace Examples\HashServer;

/**
 * This Daemon has been created to demonstrate the Workers API in 2.0.
 *
 * It creates two workers: a simple closure-based worker that computers factors, and
 * an object-based Prime Numbers worker.
 *
 * It runs jobs randomly and in response to signals and writes the jobs in a log to the MySQL table
 * described in the db.sql file.
 *
 */
class Daemon extends \Core_Daemon
{
    protected $loop_interval = 0;
    protected $idle_probability = 0.5;

    /**
     * Array of HashWorker call id's mapped to $reply closures. We stash the $reply here when we do a HashWorker call
     * and access it from the worker's onReturn and onTimeout handlers to send a reply to the client
     * @var array
     */
    public $reply_cache = array();

    protected function setup_plugins()
    {
        // PHP 5.3 Closure Hack. Fixed in 5.4.
        $that = $this;

        $this->plugin('Lock_File');

        // This application will start a socket server using the Core_Plugin_Server class.
        // It will listen on the given IP and Port for incoming connections.
        // The IP, port, etc themselves are defined in the server.ini file.
        // We're using the INI file here only because it's a convenient way to demonstrate using the INI plugin.

        $this->plugin('Ini');
        $this->Ini->filename = BASE_PATH . '/server.ini';
        $this->Ini->required_sections = array('server');

        // We want to use the values from the Ini file later in this method when we create the socket server plugin.
        // The "magic" of parsing the ini file is done in the setup() method so we'll manually call the setup() method
        // which is normally called for you after this setup_plugins() method returns
        $this->Ini->setup();

        // Now we create a Server using the Core_Plugin_Server class. The plugin handles client connections and I/O for us.
        // We simply configure the server and add a few commands that it will match client input against.
        $this->plugin('Server');
        $this->Server->blocking = false;
        $this->Server->ip       = $this->Ini['server']['ip'];
        $this->Server->port     = $this->Ini['server']['port'];

        // Each of the commands includes a regex that client input is matched against, and a Callable that, when matched,
        // will be passed the array of $matches, a $reply function to send a reply message to the Client, and a $printer
        // function that will write to stdout and/or the application log file.
        $this->Server->newCommand ('/CLIENT_CONNECT/', function($matches, $reply, $printer) {
            $printer('Client Connected');
        })

        ->newCommand ('/md5 (.+)/', function($matches, $reply, $printer) {
            $reply(md5(trim($matches[1])));
        })

        ->newCommand ('/(sha1|md5) x(\d+) (.+)/', function($matches, $reply, $printer) use($that) {
            // This command lets a user recursively hash a string hundreds, thousands, even millions of times.
            // We will pass this call off to a background worker to avoid blocking the event loop.
            // Since we need to write a reply to the client, and we cannot reply directly from a background process, we
            // save a reference to the $reply in a local array that we can access in the HashWorker's onReturn callback.

            $algorithm = $matches[1];
            $count     = $matches[2];
            $string    = $matches[3];

            $call_id = $that->HashWorker($algorithm, $count, $string);
            $that->reply_cache[$call_id] = $reply;
        })

        ->newCommand ('/sha1 (.+)/', function($matches, $reply, $printer) {
            $reply(sha1($matches[1]));
        })

        ->newCommand ('/(.+)/', function($matches, $reply, $printer) {
            if (trim($matches[1]))
                $printer('Unknown Command: ' . $matches[1]);
        });
    }

    protected function setup_workers()
    {
        // PHP 5.3 Closure Hack. Fixed in 5.4.
        $that = $this;

        // Simple hash operations will be performed in-process with the reply written immediately back to the client.
        // But the server exposes a recursive hash feature that lets you hash-your-hash N times to produce a result
        // that takes more time to hash and thus takes more time to brute-force. These will be outsourced to a worker process.
        $this->worker('HashWorker', function($algorithm, $count, $string)  {
            if (!in_array($algorithm, array('sha1', 'md5')))
                throw new \Exception('Invalid Input! Unknown algorithm: ' . $algorithm);

            if (!is_numeric($count) || !is_scalar($string))
                throw new \Exception('Invalid Input! Expected numeric count and scalar string input.');

            for ($i=0; $i<$count; $i++)
                $string = $algorithm($string);

            return $string;
        });

        $this->HashWorker->timeout(180);
        $this->HashWorker->workers(2);
        $this->HashWorker->onReturn(function($call, $log) use($that) {
            $reply = $that->reply_cache[$call->id];
            $reply($call->return);
            unset($that->reply_cache[$call->id]);
        });

        $this->HashWorker->onTimeout(function($call, $log) use($that) {

            $log('Worker Timeout! Command: ' . $call->args[0][0]);

            $reply = $that->reply_cache[$call->id];
            $reply("error: timeout occurred while processing '{$call->args[0][0]}'");
            unset($that->reply_cache[$call->id]);
        });
    }


    protected function setup()
    {
        $this->log("The HashServer Application is Running at {$this->Ini[server][ip]}:{$this->Ini[server][port]}");
    }


    protected function execute()
    {
        // This required method is unused in this application.
    }


    protected function log_file()
    {
        $dir = '/var/log/daemons/hashserver';
        if (@file_exists($dir) == false)
            @mkdir($dir, 0777, true);

        if (@is_writable($dir) == false)
            $dir = BASE_PATH . '/logs';

        return $dir . '/log_' . date('Ymd');
    }
}
