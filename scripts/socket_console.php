#!/usr/bin/php
<?php
/**
 * A simple console that lets you communicate and test your Core_Lib_Server enabled application
 *
 * User: Shane Harter
 * Date: 11/12/12
 */

echo PHP_EOL, "PHP Simple Daemon - Socket Console";
echo PHP_EOL, "Enter ip:port to Connect";
echo PHP_EOL;

stream_set_blocking(STDIN, 0);
$socket         = false;
$input          = '';
$macro_input    = '';
$prompt         = true;
$flash          = '';

while(true) {

    // Every few iterations verify that the socket connection is still open
    if ($socket && ($input || mt_rand(1,3) == 2) && !is_resource($socket)) {
        out("Socket Connection Terminated");
        $prompt = true;
        $socket = null;
        if (!$input)
            echo PHP_EOL;
    }

    if ($input || $prompt)
        if (!$socket)
            echo "CONNECT > ";
        else
            echo "SEND > ";

    $prompt = false;
    if ($macro_input) {
        $input = $macro_input;
        $macro_input = '';
    } else {
        $input  = fgets(STDIN);
    }

    $prompt = $input == "\n";
    $input  = trim($input);

    try {

        switch(true) {
            case empty($input):
                continue;

            case input() == 'exit':
                exit;

            case $socket && input() == 'help':
                $out = array();
                $out[] = '';
                $out[] = 'Available Commands:';
                $out[] = 'exit';
                $out[] = 'help                Display this help';
                $out[] = '[string]            Any provided string will be sent to the server. ';
                $out[] = '';
//                $out[] = 'Note:';
//                $out[] = 'To send any shell commands (eg "exit" or "help") as a command to the server, escape them with a leading slash, eg /help';
//                $out[] = '';

                out(implode(PHP_EOL, $out));
                continue;

            case input() == 'help':

                $out = array();
                $out[] = '';
                $out[] = 'Available Commands:';
                $out[] = 'exit';
                $out[] = 'help           Display this help';
                $out[] = 'ip:port        Connect to the socket server at the provided ip address and port';
                $out[] = '';

                out(implode(PHP_EOL, $out));
                continue;

            case $socket && is_string($input):
                out("Sending Command...");
                fputs($socket, $input, strlen($input));
                out("\n");
                out("Response:");
                $out = '';
                while ($_out = fgets($socket, 2048))
                    $out .= $_out;

                out($out);
                $out = '';
                continue;

            case !$socket && strpos($input, ':'):
                $conn = explode(':', $input);
                out("Connecting to {$conn[0]}:{$conn[1]}");
                $errno = $errstr = null;
                $socket = fsockopen($conn[0], $conn[1], $errno, $errstr, 60);

                if (!$socket) {
                    out("Connection Error [{$errno}] $errstr");
                }

                continue;

            case $socket:
                throw new Exception("Invalid Command");

            default:
                throw new Exception('Invalid Connection String: Valid ip:port required. Example: 127.0.0.1:8000');
        }

    }
    catch(Exception $e)
    {
        echo $e->getMessage(), PHP_EOL, PHP_EOL;
    }

    usleep(20 * 1000);
}

function out($out)
{
    global $flash;
    if ($flash) {
        echo $flash, PHP_EOL;
        $flash = '';
    }

    echo $out, PHP_EOL;
}

function macro($id) {
    global $input, $macro_input, $prompt, $address;
    if ($macro_input)
        $macro_input = '';

    switch($id){
        case 1:
            // Macro 1 reads an optional PID from a pid-reset command.
            // Example:   SIG> pid 12345    The current pid will be released and this macro will pull 12345 out and set
            //                              it as the new pid.
            $arg = @func_get_arg(1);
            if (!$arg || strlen(trim($arg)) < 4)
                return;

            $macro_input = trim(str_replace('pid', '', $arg));
            break;
    }

    if ($macro_input)
        $input = '';
}

function input($in = null, Array $commands = array('pid', 'help', 'exit')) {
    global $input, $flash;
    if (empty($in))
        $in = $input;

    if (empty($in))
        return null;

    $matches = array();
    foreach ($commands as $command)
        if ($in == substr($command, 0, strlen($in)))
            $matches[] = $command;

    if (count($matches) > 1)
        out("Ambiguous Command. Matches: " . implode(', ', $matches));

    if (count($matches)) {
        if ($in != $matches[0])
            $flash = $matches[0];

        return $matches[0];
    }

    return null;
}
