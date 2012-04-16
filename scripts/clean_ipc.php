<?php
/**
 * PHP Simple Daemon IPC Cleaner
 * @author Shane Harter
 * Date: 4/15/12
 * Time: 6:06 PM
 *
 * When developing a daemon that uses Workers, it's common that crashes and kill -9's leave certain
 * kernel-level interprocess communication (IPC) resources unreleased. This script will release all allocated shared
 * memory segments, message queues, and semaphores.
 *
 * You should not run this on a production server unless you know what you're doing. Doing so could interfere with other
 * applications, servers and daemons that may be running on the system.
 *
 * If you do need to clean up IPC resources in a more selective way, on linux you can use the ipcs and ipcrm commands.
 * Running `ipcs` will show you information about each memory segment, queue and semaphore. You can then pass the ID
 * displayed there in column 2 to ipcrm, which is what we're doing in this script.
 *
 * You have to pass iprcm a flag indicating the type of resources (-m, -q, and -s for Shared Memory, Queues and Semaphores, respectively), and then the ID.
 */

$warning = PHP_EOL . "WARNING: This script releases all SystemV IPC resources: Shared Memory, Message Queues and Semaphores. Only run this if you want ALL resources released.
                      Consider modifying it for partial release if you have other running systems on this server that could be using these resources.
                      NOTE: YOU MUST EDIT THIS SCRIPT AND REMOVE THIS WARNING TO CONTINUE" . PHP_EOL . PHP_EOL;

// Remove this next line to continue:
die($warning);

$ipcs = array();
exec('ipcs', $ipcs);
foreach($ipcs as $row) {

    if (strpos($row, 'Shared Memory Segments') > 0) {
        echo PHP_EOL, "Cleaning Shared Memory Segments...";
        $flag = '-m';
        continue;
    }

    if (strpos($row, 'Message Queues') > 0) {
        echo PHP_EOL, "Cleaning Message Queues...";
        $flag = '-q';
        continue;
    }

    if (strpos($row, 'Semaphore Arrays') > 0) {
        echo PHP_EOL, "Cleaning Semaphore Arrays...";
        $flag = '-s';
        continue;
    }

    $row = explode(' ', $row);

    if (!isset($row[1]))
        continue;

    $id  = trim($row[1]);
    if (!is_numeric($id))
        continue;

    // Note: Consider adding filters here if you want to selectively remove resources

    echo PHP_EOL, "Removing Address {$id}";
    @exec("ipcrm {$flag} {$id}");
}

echo PHP_EOL, "** DONE **", PHP_EOL, PHP_EOL;