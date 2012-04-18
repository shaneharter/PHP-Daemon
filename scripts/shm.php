#!/usr/bin/php
<?php
/**
 * PHP Simple Daemon Shared Memory Viewer
 * @author Shane Harter
 * Date: 4/17/12
 * Time: 5:59 PM
 */


$opts = getopt('w:i:', array('useexport'));
if (false == ($opts['w'] )) {
    $file = basename(__FILE__);
    die("
    PHP Simple Daemon
    Shared Memory Viewer

    Usage:
    # php $file -w workerid [-i itemid] [--useexport]

        -w
          You can get the workerid by passing the dump signal to the running daemon:
            kill -10 [pid]
          The Worker ID will be listed after the worker's alias

        -i optional
          The variable name set. Scalar expected.
          Leave blank to print the header written to the memory block by the worker mediator.

        --useexport optional
          Will use var_export() to print the variable contents as valid PHP.
          Useful for objects and arrays that you want to re-construct.\n\n"
    );
}

$shm = shm_attach($opts['w']);

if (!isset($opts['i'])) {
    echo PHP_EOL . "PHP Daemon Memory Block Header:\n\n";
    $opts['i'] = 1;
}

if (!shm_has_var($shm, $opts['i'])) {
    die(PHP_EOL . PHP_EOL . "null" . PHP_EOL . PHP_EOL);
}

$var = shm_get_var($shm, $opts['i']);

if (isset($opts['useexport']))
    echo var_export($var, true), PHP_EOL, PHP_EOL;
else
    print_r($var);
