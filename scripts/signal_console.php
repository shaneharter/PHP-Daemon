#!/usr/bin/php
<?php
/**
 * A simple console that lets you send signals to a process
 * User: shane
 * Date: 4/24/12
 * Time: 6:03 PM
 * To change this template use File | Settings | File Templates.
 */

define('BASE_PATH', dirname(dirname(__FILE__)));

$pidfiles = array(
    'Example'           =>  BASE_PATH . '/Example/pid',
    'ExampleWorkers'    =>  BASE_PATH . '/ExampleWorkers/pid',
);


$index = array_keys($pidfiles);
$string = '';
foreach($index as $id => $key) {
    $string .= sprintf(' [%s] %s', $id, $key);
}

echo PHP_EOL, "PHP Simple Daemon - Signal Console";
echo PHP_EOL, "Enter PID";
if ($string)
    echo " or Select a Daemon:", $string;
echo PHP_EOL;
echo PHP_EOL;

$pid = false;

stream_set_blocking(STDIN, 0);
$input = '';
$prompt = true;

while(true) {

    // Every few iterations verify that the pid still exists
    if ($pid && ($input || mt_rand(1,3) == 2) && (file_exists("/proc") && !file_exists("/proc/{$pid}"))) {
        $prompt = true;
        $pid = false;
        if (!$input)
            echo PHP_EOL;
    }

    if ($input || $prompt)
        if (!$pid)
            echo "PID > ";
        else
            echo "SIG > ";

    $prompt = false;
    $input  = strtolower(fgets(STDIN));
    $prompt = ($input != trim($input));
    $input  = trim($input);

    try {

        switch(true) {
            case empty($input):
                continue;

            case $input == 'exit':
                exit;

            case $pid && $input == 'help':

                $out = array();
                $out[] = '';
                $out[] = 'Available Commands:';
                $out[] = 'exit';
                $out[] = 'help                Display this help';
                $out[] = 'pid                 Display a PID prompt to enter a new PID';
                $out[] = '[integer]           A valid signal';
                $out[] = '';

                echo implode(PHP_EOL, $out), PHP_EOL;
                continue;

            case $input == 'help':
                $out = array();
                $out[] = '';
                $out[] = 'Available Commands:';
                $out[] = 'exit';
                $out[] = 'help                Display this help';
                $out[] = '[integer]           A valid process id';
                $out[] = '';
                $out[] = 'Shortcuts:';
                foreach($index as $i => $f) {
                    $out[] = str_pad($i, 20, ' ', STR_PAD_RIGHT) . "Shortcut to read a PID from " . $pidfiles[$f];
                }
                $out[] = '';
                $out[] = 'Shortcuts come from the $pidfiles array. Add your own shortcuts to make life easier.';
                $out[] = '';

                echo implode(PHP_EOL, $out), PHP_EOL;
                continue;

            case $pid && is_numeric($input) && $input > 0 && $input < 50:
                echo "Sending Signal...", PHP_EOL;
                posix_kill($pid, $input);
                continue;

            case $pid && $input == 'pid':
                $pid = false;
                continue;

            case isset($index[$input]):
                $key = $index[$input];
                $file = $pidfiles[$key];

                if (!file_exists($file))
                    throw new Exception("Sorry, Pid File Not Found! Tried: $file");

                $pid = file_get_contents($file);
                continue;

            case (is_numeric($input) && $input < 40000 && $input > 1):
                // On linux systems, easily verify if the pid is valid by looking for the process in the /proc directory
                if (file_exists("/proc") && !file_exists("/proc/$input"))
                    throw new Exception("Pid `$input` Does Not Exist");

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
