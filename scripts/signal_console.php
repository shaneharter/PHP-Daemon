#!/usr/bin/php
<?php
/**
 * A simple console that lets you send signals to a process
 * Set your own pid file locations in the array below so you can read the PIDs from them by passing in their array ordinal.
 * @example To attach the signal console to the pidfile listed 2nd in the array below, you'd type -1 at the signal prompt.
 *          The dash indicates a shortcut (instead of attaching to PID 1) and "1" is the array ordinal for the 2nd item.
 *
 * User: Shane Harter
 * Date: 4/24/12
 */

define('BASE_PATH', dirname(dirname(__FILE__)));

$pidfiles = array(
    'PrimeNumbers'  =>  BASE_PATH . '/Examples/PrimeNumbers/pid',
    'Tasks'         =>  BASE_PATH . '/Examples/Tasks/pid',
    'LongPoll'      =>  BASE_PATH . '/Examples/LongPoll/pid',
);

$index = array_keys($pidfiles);
$string = '';
foreach($index as $id => $key) {
    $string .= sprintf('%s [-%s] %s', PHP_EOL, $id, $key);
}

echo PHP_EOL, "PHP Simple Daemon - Signal Console";
echo PHP_EOL, "Enter PID";
if ($string)
    echo " or Select a Daemon:", $string;
echo PHP_EOL;
echo PHP_EOL;


stream_set_blocking(STDIN, 0);
$pid            = false;
$input          = '';
$macro_input    = '';
$prompt         = true;
$flash          = '';

while(true) {

    $flash = '';

    // Every few iterations verify that the pid still exists
    if ($pid && ($input || mt_rand(1,3) == 2) && (file_exists("/proc") && !file_exists("/proc/{$pid}"))) {
        out("Process {$pid} Has Exited");
        $prompt = true;
        $pid    = false;
        if (!$input)
            echo PHP_EOL;
    }

    if ($input || $prompt)
        if (!$pid)
            echo "PID > ";
        else
            echo "SIG > ";

    $prompt = false;
    if ($macro_input) {
        $input = $macro_input;
        $macro_input = '';
    } else {
        $input  = strtolower(fgets(STDIN));
    }

    $prompt = $input == "\n";
    $input  = trim($input);

    try {

        switch(true) {
            case empty($input):
                continue;

            case input() == 'exit':
                exit;

            case $pid && input() == 'help':

                $out = array();
                $out[] = '';
                $out[] = 'Available Commands:';
                $out[] = 'exit';
                $out[] = 'help                Display this help';
                $out[] = 'pid [integer]       Display a PID prompt to enter a new PID, and optionally pass a PID to switch directly to';
                $out[] = '[integer]           A valid signal';
                $out[] = '';

                out(implode(PHP_EOL, $out));
                continue;

            case input() == 'help':
                $out = array();
                $out[] = '';
                $out[] = 'Available Commands:';
                $out[] = 'exit';
                $out[] = 'help                Display this help';
                $out[] = '[integer]           A valid process id';
                $out[] = '';
                $out[] = 'Shortcuts:';
                foreach($index as $i => $f) {
                    $out[] = str_pad("-$i", 20, ' ', STR_PAD_RIGHT) . $pidfiles[$f];
                }
                $out[] = '';
                $out[] = 'Shortcuts come from the $pidfiles array. Add your own shortcuts to make life easier.';
                $out[] = '';

                out(implode(PHP_EOL, $out));
                continue;

            case $pid && is_numeric($input) && $input > 0 && $input < 50:
                out("Sending Signal...");
                posix_kill($pid, $input);
                continue;

            case input(substr($input, 0, 3)) == 'pid':
                if ($pid) {
                    out("Releasing PID...");
                    $pid = false;
                }
                macro(1, $input);
                continue;

            case $input <= 0 && isset($index[abs($input)]):
                $key = $index[abs($input)];
                $file = $pidfiles[$key];

                if (!file_exists($file))
                    throw new Exception("Sorry, Pid File Not Found! Tried: $file");

                $pid = file_get_contents($file);
                out("Setting PID From Shortcut: {$pid}");
                continue;

            case !$pid && (is_numeric($input) && $input < 40000 && $input > 1):
                // On linux systems, easily verify if the pid is valid by looking for the process in the /proc directory
                if (file_exists("/proc") && !file_exists("/proc/$input"))
                    throw new Exception("Pid `$input` Does Not Exist");

                out("Setting PID: {$input}");
                $pid = $input;
                continue;

            case $pid:
                throw new Exception("Invalid Signal");

            default:
                throw new Exception('Invalid Input: Valid PID or Shortcut Required');
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
