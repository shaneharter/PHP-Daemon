<?php
if (!$matches && preg_match('/^show local (\d+)/i', $input, $matches) == 1) {
    if (!is_array($this->calls)) {
        echo "No Calls In Memory", PHP_EOL;
        continue;
    }

    if (isset($this->calls[$matches[1]]))
        $message = print_r(@$this->calls[$matches[1]], true);
    else
        $message = "Item Does Not Exist";
}

if (!$matches && preg_match('/^show[\s]*(\d+)?$/i', $input, $matches) == 1) {
    if (empty($this->shm)) {
        echo "Shared Memory Not Connected Yet", PHP_EOL;
        continue;
    }

    if (count($matches) == 1) {
        $id = 1; // show the header
    } else {
        $id = $matches[1];
    }

    $message = print_r(@shm_get_var($this->shm, $id), true);
}

if (!$matches && preg_match('/^signal (\d+)/i', $input, $matches) == 1) {
    posix_kill(Core_Daemon::get('parent_pid'), $matches[1]);
    $message = "Signal Sent";
}

if (!$matches && preg_match('/^skipfor (\d+)/i', $input, $matches) == 1) {
    $time = time() + $matches[1];
    $state("skip_until", $time);
    $message = "Skipping Breakpoints for $matches[1] seconds. Will resume at " . date('H:i:s', $time);
}






case 'status':
                        if (Core_Daemon::is('parent')) {
                          $out = array();
                          $out[] = '';
                          $out[] = 'Daemon Process';
                          $out[] = 'Alias: ' . $this->alias;
                          $out[] = 'IPC ID: ' . $this->id;
                          $out[] = 'Workers: ' . count($this->processes);
                          $out[] = 'Max Workers: ' . $this->workers;
                          $out[] = 'Running Jobs: ' . count($this->running_calls);
                          $out[] = '';
                          $out[] = 'Processes:';
                          if ($this->processes)
                            $out[] = $this->processes;
                          else
                            $out[] = 'None';

                          $out[] = '';
                          $message = implode(PHP_EOL, $out);
                        } else {
                          $out = array();
                          $out[] = '';
                          $out[] = 'Worker Process';
                          $out[] = 'Alias: ' . $this->alias;
                          $out[] = 'IPC ID: ' . $this->id;
                          $out[] = '';
                          $message = implode(PHP_EOL, $out);
                        }
                        break;






                    case 'cleanipc':
                        if (!$state('warned')) {
                          $message = "WARNING: This will release all Shared Memory and Message Queue IPC resources. Only run this if you want ALL resources released.";
                          $message .= "If this is a production server, you should probably not do this. Does NOT release semaphores. To clean all types, including semaphores, use the scripts/clean_ipc.php tool";
                          $message .= PHP_EOL . PHP_EOL . "Repeat command to proceed with the IPC cleaning.";
                          $state('warned', true);
                          break;
                        }
                        $script = dirname(dirname(dirname(dirname(__FILE__)))) . '/scripts/clean_ipc.php';
                        @passthru("php $script -s --confirm");
                        echo PHP_EOL;
                        break;





                    case 'types':
                        $out = array();
                        $out[] = 'Message Types:';
                        $out[] = '1     Worker Sending "onReturn" message to the Daemon';
                        $out[] = '2     Worker Notifying Daemon that it received the Call message and will now begin work.';
                        $out[] = '3     Daemon sending a Call message to the Worker';
                        $out[] = '';
                        $out[] = 'Statuses:';
                        $out[] = '0     Uncalled';
                        $out[] = '1     Called';
                        $out[] = '2     Running';
                        $out[] = '3     Returned';
                        $out[] = '4     Cancelled';
                        $out[] = '10    Timeout';
                        $out[] = '';
                        $message = implode(PHP_EOL, $out);
                        break;




                        $out[] = 'call [f] [a,b..]  Call a worker\'s function in the local process, passing remaining values as args. Return true: a "continue" will be implied. Non-true: keep you at the prompt';
                        $out[] = 'cleanipc          Clean all systemv resources including shared memory and message queues. Does not remove semaphores. REQUIRES CONFIRMATION.  ';


                        $out[] = 'show [n]          Display the Nth item in shared memory. If no ID is passed, `show` will show the shared memory header.';
                        $out[] = 'show local [n]    Display the Nth item in local memory - from the $this->calls array';
                        $out[] = 'status            Display current process stats';
                        $out[] = 'types             Display a table of message types and statuses so you can figure out what they mean.';