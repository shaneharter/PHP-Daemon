<?php

/**
 * Create a simple socket server.
 * Supply an IP and Port for incoming connections. Add any number of Core_Lib_Command objects to parse client input.
 *
 * Used in blocking mode, this can be the backbone of a Core_Daemon based server with a loop_interval set to Null.
 * Alternatively, you could set $blocking = false and use it to interact with a timer-based Core_Daemon application.
 *
 * Can be combined with the Worker API by adding Command objects that call methods attached to a Worker. That would leave
 * the main Application process to handle connections and client input, worker process management, and passing commands
 * between client input to worker calls, and worker return values to client output.
 *
 */
class Core_Plugin_Server implements Core_IPlugin
{
    const COMMAND_CONNECT       = 'CLIENT_CONNECT';
    const COMMAND_DISCONNECT    = 'CLIENT_DISCONNECT';
    const COMMAND_DESTRUCT      = 'SERVER_DISCONNECT';

    /**
     * @var Core_Daemon
     */
    public $daemon;

    /**
     * The IP Address server will listen on
     * @var string IP
     */
    public $ip;

    /**
     * The Port the server will listen on
     * @var integer
     */
    public $port;

    /**
     * The socket resource
     * @var Resource
     */
    public $socket;

    /**
     * Maximum number of concurrent clients
     * @var int
     */
    public $max_clients = 10;

    /**
     * Maximum bytes read from a given client connection at a time
     * @var int
     */
    public $max_read = 1024;

    /**
     * Array of stdClass client structs.
     * @var stdClass[]
     */
    public $clients = array();

    /**
     * Is this a Blocking server or a Polling server? When in blocking mode, the server will
     * wait for connections & commands indefinitely. When polling, it will look for any connections or commands awaiting
     * a response and return immediately if there aren't any.
     * @var bool
     */
    public $blocking = false;

    /**
     * Write verbose logging to the application log when true.
     * @var bool
     */
    public $debug = true;

    /**
     * Array of Command objects to match input against.
     * Note: In addition to input rec'd from the client, the server will emit the following commands when appropriate:
     * CLIENT_CONNECT(stdClass Client)
     * CLIENT_DISCONNECT(stdClass Client)
     * SERVER_DISCONNECT
     *
     * @var Core_Lib_Command[]
     */
    private $commands = array();


    public function __construct(Core_Daemon $daemon) {
        $this->daemon = $daemon;
    }

    public function __destruct() {
        unset($this->daemon);
    }

    /**
     * Called on Construct or Init
     * @return void
     */
    public function setup() {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, 0);
        if (!socket_bind($this->socket, $this->ip, $this->port)) {
            $errno = socket_last_error();
            $this->error(sprintf('Could not bind to address %s:%s [%s] %s', $this->ip, $this->port, $errno, socket_strerror($errno)));
            throw new Exception('Could not start server.');
        }

        socket_listen($this->socket);
        $this->daemon->on(Core_Daemon::ON_POSTEXECUTE, array($this, 'run'));
    }

    /**
     * Called on Destruct
     * @return void
     */
    public function teardown() {
        foreach(array_keys($this->clients) as $slot)
            $this->disconnect($slot);

        @ socket_shutdown($this->socket, 1);
        usleep(500);
        @ socket_shutdown($this->socket, 0);
        @ socket_close($this->socket);
        $this->socket = null;
    }

    /**
     * This is called during object construction to validate any dependencies
     * NOTE: At a minimum you should ensure that if $errors is not empty that you pass it along as the return value.
     * @return Array  Return array of error messages (Think stuff like "GD Library Extension Required" or "Cannot open /tmp for Writing") or an empty array
     */
    public function check_environment(Array $errors = array()) {
        if (!is_callable('socket_create'))
            $errors[] = 'Socket support is currently unavailable: You must add the php_sockets extension to your php.ini or recompile php with the --enable-sockets option set';

        return $errors;
    }

    /**
     * Add a Core_Lib_Command object to the command queue. Input from a client is evaluated against these commands
     * in the order they are added
     *
     * @param Core_Lib_Command $command
     */
    public function addCommand(Core_Lib_Command $command) {
        $this->commands[] = $command;
        return $this;
    }

    /**
     * An alternative to addCommand - a simple factory for Core_Lib_Command objects.
     * @param $regex
     * @param $callable
     */
    public function newCommand($regex, $callable) {
        $cmd = new Core_Lib_Command();
        $cmd->regex = $regex;
        $cmd->callable = $callable;
        return $this->addCommand($cmd);
    }

    public function run() {

        // Build an array of sockets and select any with pending I/O
        $read = array (
            0 => $this->socket
        );

        foreach($this->clients as $client)
            $read[] = $client->socket;

        $result = @ socket_select($read, $write = null, $except = null, $this->blocking ? null : 1);
        if ($result === false || ($result === 0 && $this->blocking)) {
            $this->error('Socket Select Interruption: ' . socket_strerror(socket_last_error()));
            return false;
        }

        // If the master socket is in the $read array, there's a pending connection
        if (in_array($this->socket, $read))
            $this->connect();

        // Handle input from sockets in the $read array.
        $daemon = $this->daemon;
        $printer = function($str) use ($daemon) {
            $daemon->log($str, 'SocketServer');
        };

        foreach($this->clients as $slot => $client) {
            if (!in_array($client->socket, $read))
                continue;

            $input = socket_read($client->socket, $this->max_read);
            if ($input === null) {
                $this->disconnect($slot);
                continue;
            }

            $this->command($input, array($client->write, $printer));
        }
    }

    private function connect() {
        $slot = $this->slot();
        if ($slot === null)
            throw new Exception(sprintf('%s Failed - Maximum number of connections has been reached.', __METHOD__));

        $this->debug("Creating New Connection");

        $client = new stdClass();
        $client->socket = socket_accept($this->socket);
        if (empty($client->socket))
            throw new Exception(sprintf('%s Failed - socket_accept failed with error: %s', __METHOD__, socket_last_error()));

        socket_getpeername($client->socket, $client->ip);

        $client->write = function($string, $term = "\r\n") use($client) {
            if($term)
                $string .= $term;

            return socket_write($client->socket, $string, strlen($string));
        };

        $this->clients[$slot] = $client;

        // @todo clean this up
        $daemon = $this->daemon;
        $this->command(self::COMMAND_CONNECT, array($client->write, function($str) use ($daemon) {
            $daemon->log($str, 'SocketServer');
        }));
    }

    private function command($input, Array $args = array()) {
        foreach($this->commands as $command)
            if($command->match($input, $args) && $command->exclusive)
                break;
    }

    private function disconnect($slot) {
        $daemon = $this->daemon;
        $this->command(self::COMMAND_DISCONNECT, array($this->clients[$slot]->write, function($str) use ($daemon) {
            $daemon->log($str, 'SocketServer');
        }));

        @ socket_shutdown($this->clients[$slot]->socket, 1);
        usleep(500);
        @ socket_shutdown($this->clients[$slot]->socket, 0);
        @ socket_close($this->clients[$slot]->socket);
        unset($this->clients[$slot]);
    }

    private function slot() {
        for($i=0; $i < $this->max_clients; $i++ )
            if (empty($this->clients[$i]))
                return $i;

        return null;
    }

    private function debug($message) {
        if (!$this->debug)
            return;

        $this->daemon->debug($message, 'SocketServer');
    }

    private function error($message) {
        $this->daemon->error($message, 'SocketServer');
    }

    private function log($message) {
        $this->daemon->log($message, 'SocketServer');
    }
}
