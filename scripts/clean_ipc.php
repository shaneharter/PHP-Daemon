<?php
/**
 * Created by JetBrains PhpStorm.
 * User: Shane Harter
 * Date: 4/15/12
 * Time: 6:06 PM
 */

$warning = PHP_EOL . "WARNING: This script releases all SystemV IPC resources: Shared Memory, Message Queues and Semaphores. Only run this if you want ALL resources released.
                      Consider modifying it for partial release if you have other running systems on this server that could be using these resources.
                      NOTE: YOU MUST EDIT THIS SCRIPT AND REMOVE THIS WARNING TO CONTINUE" . PHP_EOL . PHP_EOL;

// Remove this next line to continue:
die($warning);

$ipcs = array(); exec('ipcs', $ipcs);
foreach($ipcs as $row) {

    if (strpos($row, 'Shared Memory Segments') > 0) {
        echo PHP_EOL, "Cleaning Shared Memory Segments...";
        $function = 'close_shm';
        continue;
    }

    if (strpos($row, 'Message Queues') > 0) {
        echo PHP_EOL, "Cleaning Message Queues...";
        $function = 'close_msg';
        continue;
    }

    if (strpos($row, 'Semaphore Arrays') > 0) {
        echo PHP_EOL, "Cleaning Semaphore Arrays...";
        $function = 'close_sem';
        continue;
    }

    $row = explode(' ', $row);
    $id  = $row[0];
    if (substr($id, 0, 2) != '0x')
        continue;

    // Note: Consider adding filters here if you want to selectively remove resources

    $id = substr($id, 2);
    $id = hexdec($id);

    echo PHP_EOL, "Processing Address {$id}";
    @$function($id);
}

echo PHP_EOL, "** DONE **", PHP_EOL, PHP_EOL;

function close_shm($id) {
    $id = shm_attach($id);
    shm_remove($id);
    shm_detach($id);
}

function close_sem($id) {
    $id = sem_acquire($id);
    sem_release($id);
    sem_remove($id);
}

function close_msg($id) {
    $id = msg_get_queue($id);
    msg_remove_queue($id);
}