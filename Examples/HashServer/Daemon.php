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
    protected $idle_probability = 0.25;

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

        // This is a bit of a hack, but we want to use the Ini plugin below in the socket server plugin
        // So we will manually call the setup() method of Ini which does the "magic" of parsing the Ini file.
        $this->Ini->setup();

        // Now we will create a Server object using the Core_Plugin_Server class
        // This will handle client connections for us. The server will run incoming commands against the array of
        // Core_Lib_Command objects we will create below.
        $this->plugin('Server');
        $this->Server->ip = $this->Ini['server']['ip'];
        $this->Server->port = $this->Ini['server']['port'];


        $cmd = new \Core_Lib_Command();
        $cmd->regex = '/CLIENT_CONNECT/';
        $cmd->callable = function($matches, $reply, $printer) {
            $printer('Client Connected');
        };
        $this->Server->addCommand($cmd);


        $cmd = new \Core_Lib_Command();
        $cmd->regex = '/md5 (.+)/';
        $cmd->callable = function($matches, $reply, $printer) {
            $reply(md5(trim($matches[1])));
        };
        $this->Server->addCommand($cmd);


        $cmd = new \Core_Lib_Command();
        $cmd->regex = '/sha1 x(\d+) (.+)/';
        $cmd->description = 'Repeated SHA1 hash. For example, recursively hash "foo" 100 times using SHA1: sha1 x100 foo';
        $cmd->callable = array($this, 'Sha1Worker');
        $this->Server->addCommand($cmd);


        $cmd = new \Core_Lib_Command();
        $cmd->regex = '/sha1 (.+)/';
        $cmd->callable = function($matches, $reply, $printer) {
            $reply(sha1($matches[1]));
        };
        $this->Server->addCommand($cmd);


        $cmd = new \Core_Lib_Command();
        $cmd->regex = '/(.+)/';
        $cmd->callable = function($matches, $reply, $printer) {
            $printer('CRAZY THING RECD ' . $matches[1]);
        };
        $this->Server->addCommand($cmd);
    }

    protected function setup_workers()
    {
        // PHP 5.3 Closure Hack. Fixed in 5.4.
        $that = $this;

        // Simple hash operations will be performed in-process with the reply written immediately back to the client.
        // But the server exposes a recursive hash feature that lets you hash-your-hash N times to produce a result
        // that takes more time to hash and thus takes more time to brute-force. These will be outsourced to a worker process.
        $this->worker('Sha1Worker', function($matches, $reply, $printer)  {
            if (!is_array($matches))
                throw new Exception('Invalid Input! Expected Array. Given: ' . gettype($matches));

            $count = $matches[1];
            $string = $matches[2];

            if (!is_numeric($count) || !is_string($string))
                throw new Exception('Invalid Input! Expected Matches Array as [Command, Count, String]');

            for ($i=0; $i<$count; $i++)
                $string = sha1($string);

            return function() use($printer, $string) {
               return $printer($string);
            };
        });

        $this->Sha1Worker->timeout(180);
        $this->Sha1Worker->workers(2);
        $this->Sha1Worker->onReturn(function(\Core_Worker_Call $call, $log) use($that) {
            $callable = $call->return;
            $callable();
        });

        $this->Sha1Worker->onTimeout(function($call, $log) use($that) {
            $replyer = $call->args[2];
            $replyer('Sha1 Hash Timeout :(');
        });
    }


    protected function setup()
    {
        $this->log("The HashServer Application is Running at {$this->Ini[server][ip]}:{$this->Ini[server][port]}");
    }


    protected function execute()
    {
        // This required method is unused in blocking-based servers.
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
