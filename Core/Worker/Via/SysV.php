<?php

class Core_Worker_Via_SysV implements Core_IWorkerVia, Core_IPlugin {

    /**
     * Each SHM block has a header with needed metadata.
     */
    const HEADER_ADDRESS = 1;

    /**
     * @var Core_Worker_Mediator
     */
    public $mediator;

    /**
     * A handle to the IPC message queue
     * @var Resource
     */
    protected $queue;

    /**
     * A handle to the IPC Shared Memory resource
     * This should be a `protected` property but in a few instances in this class closures are used in a way that
     * really makes a lot of sense and they need access. I think these issues will be fixed with the improvements
     * to $this lexical scoping in PHP5.4
     * @var Resource
     */
    public $shm;

    /**
     * How big, at any time, can the IPC shared memory allocation be.
     * Default is 5MB. Will need to be increased if you are passing large datasets as Arguments or Return values.
     * @example Allocate shared memory using $this->malloc();
     * @var float
     */
    protected $memory_allocation;

    /**
     * Under-allocated shared memory is perhaps the largest possible cause of Worker failures, so if the Mediator believes
     * the memory is under-allocated it will set this variable and write the warning to the event log
     * @var Boolean
     */
    protected $memory_allocation_warning = false;

    /**
     * Array of accumulated error counts. Error thresholds are localized and when reached will
     * raise a fatal error. Generally thresholds on workers are much lower than on the daemon process
     * @var array
     */
    public $error_counts = array(
        'communication' => 0,
        'corruption'    => 0,
        'catchall'      => 0,
    );




    public function __construct()
    {
        $this->memory_allocation = 5 * 1024 * 1024;
    }

    public function __destruct()
    {
        unset($this->mediator);
    }

    /**
    * Called on Construct or Init
    * @return void
    */
    public function setup()
    {
      $this->ipc_create();
      if (Core_Daemon::is('parent')) {
          $this->shm_init();
      }
    }

    /**
    * Called on Destruct
    * @return void
    */
    public function teardown()
    {
    }

    /**
    * This is called during object construction to validate any dependencies
    * @return Array  Return array of error messages (Think stuff like "GD Library Extension Required" or "Cannot open /tmp for Writing") or an empty array
    */
    public function check_environment()
    {
    }


    /**
     * Allocate the total size of shared memory that will be allocated for passing arguments and return values to/from the
     * worker processes. Should be sufficient to hold the working set of each worker pool.
     *
     * This is can be calculated roughly as:
     * ([Max Size Of Arguments Passed] + [Max Size of Return Value]) * ([Number of Jobs Running Concurrently] + [Number of Jobs Queued, Waiting to Run])
     *
     * The memory used by a job is freed after a worker ack's the job as complete and the onReturn handler is called.
     * The total pool of memory allocated here is freed when:
     * 1) The daemon is stopped and no messages are left in the queue.
     * 2) The daemon is restarted without the --recoverworkers flag (In this case the memory is freed and released and then re-allocated.
     *    This is useful if you need to resize the shared memory the worker uses or you just want to purge any stale messages)
     *
     * Part of the Daemon API - Use from your Daemon to allocate shared memory used among all worker processes.
     *
     * @default 1 MB
     * @param $bytes
     * @throws Exception
     * @return int
     */
    public function malloc($bytes = null) {
        if ($bytes !== null) {
            if (!is_int($bytes))
                throw new Exception(__METHOD__ . " Failed. Could not set SHM allocation size. Expected Integer. Given: " . gettype($bytes));

            if (is_resource($this->shm))
                throw new Exception(__METHOD__ . " Failed. Can Not Re-Allocate SHM Size. You will have to restart the daemon without the --recoverworkers option to resize.");

            $this->memory_allocation = $bytes;
        }

        return $this->memory_allocation;
    }

    /**
    * Puts the message on the queue
    * @param $message_type
    * @param $message
    * @return boolean
    */
    public function put($call)
    {
        $that = $this;
        switch($call->status) {
            case self::UNCALLED:
            case self::RETURNED:
                $encoder = function($call) use ($that) {
                    shm_put_var($that->shm, $call->id, $call);
                    return shm_has_var($that->shm, $call->id);
                };
                break;

            default:
                $encoder = function($call) {
                    return true;
                };
        }

        $error_code = null;
        if ($encoder($call)) {

            $message = array (
                'call_id'   => $call->id,
                'status'    => $call->status,
                'microtime' => $call->time[$call->status],
                'pid'       => $this->daemon->pid(),
            );

            if (msg_send($this->queue, $call->queue, $message, true, false, $error_code)) {
                return true;
            }
        }

        $call->errors++;
        if ($this->ipc_error($error_code, $call->errors) && $call->errors < 3) {
            $this->log("Message Encode Failed for call_id {$call_id}: Retrying. Error Code: " . $error_code);
            return $this->message_encode($call_id);
        }

        return false;
    }

    /**
    * Retrieves a message from the queue
    * @param $message_type
    * @return Array  Returns a call struct.
    */
    public function get($message_type, $blocking = false)
    {
        $_message_type = $message = $message_error = null;
        msg_receive($this->queue, $message_type, $_message_type, $this->memory_allocation, $message, true, MSG_IPC_NOWAIT, $message_error);

        $that = $this;
        switch($message['status']) {
            case self::UNCALLED:
                $decoder = function($message) use($that) {
                    $call = shm_get_var($that->shm, $message['call_id']);
                    if ($message['microtime'] < $call->time[Core_Worker_Mediator::UNCALLED])    // Has been requeued - Cancel this call
                        $that->update_struct_status($call, Core_Worker_Mediator::CANCELLED);

                    return $call;
                };
                break;

            case self::RETURNED:
                $decoder = function($message) use($that) {
                    $call = shm_get_var($that->shm, $message['call_id']);
                    if ($call && $call->status == $message['status'])
                        @shm_remove_var($that->shm, $message['call_id']);

                    return $call;
                };
                break;

            default:
                $decoder = function($message) use($that) {
                    $call = $that->get_struct($message['call_id']);

                    // If we don't have a local copy of $call the most likely scenario is a --recoverworkers situation.
                    // Create a placeholder. We'll get a full copy of the struct when it's returned from the worker
                    if (!$call) {
                        $call = $that->create_struct();
                        $call->id = $message['call_id'];
                    }

                    $that->update_struct_status($call, $message['status']);
                    $call->pid = $message['pid'];
                    return $call;
                };
        }

        // Now get on with decoding the $message
        $tries = 1;
        do {
            $call = $decoder($message);
        } while(empty($call) && $this->ipc_error(null, $tries) && $tries++ < 3);

        if (!is_object($call))
            throw new Exception(__METHOD__ . " Failed. Could Not Decode Message: " . print_r($message, true));

        $this->calls[$call->id] = $this->merge_struct($call);
        return $call->id
    }

    /**
    * Returns the last error: poll after a puts or gets failure.
    * @return mixed
    */
    public function get_last_error()
    {

    }

    /**
    * The state of the queue -- The number of pending messages, memory consumption, errors, etc.
    * @return Array with some subset of these keys: messages, memory_allocation, error_count
    */
    public function state()
    {
        $tuple = array(
            'messages' => null,
            'memory_allocation' => null,
        );

        $stat = @msg_stat_queue($this->queue);
        if (is_array($stat))
            $tuple['messages'] = $stat['msg_qnum'];

        $header = @shm_get_var($this->shm, 1);
        if (is_array($header))
            $tuple['memory_allocation'] = $header['memory_allocation'];

        return $tuple;
    }

    /**
    * Perform any cleanup & garbage collection necessary.
    * @return boolean
    */
    public function garbage_collector()
    {
        foreach ($this->calls as $call_id => &$call) {
            if (!$call->gc && in_array($call->status, array(self::TIMEOUT, self::RETURNED, self::CANCELLED))) {
                unset($call->args, $call->return);
                $call->gc = true;
                if (Core_Daemon::is('parent') && shm_has_var($this->shm, $call_id))
                    shm_remove_var($this->shm, $call_id);

                continue;
            }
        }
    }

    /**
    * Drop any pending messages in the queue
    * @return boolean
    * @todo possibly add-back functionality to purge only SHM or MQ
    */
    public function purge()
    {
        $mq = $shm = true;

        if (($mq && !is_resource($this->queue)) || ($shm && !is_resource($this->shm)))
            $this->ipc_create();

        if ($mq) {
            @msg_remove_queue($this->queue);
            $this->queue = null;
        }

        if ($shm) {
            @shm_remove($this->shm);
            @shm_detach($this->shm);
            $this->shm = null;
        }
    }


    private function ipc_create() {
        $this->shm      = shm_attach($this->guid, $this->memory_allocation, 0666);
        $this->queue    = msg_get_queue($this->guid, 0666);
    }


    /**
     * Write and Verify the SHM header
     * @return void
     * @throws Exception
     */
    private function shm_init() {

        // Write a header to the shared memory block
        if (!shm_has_var($this->shm, self::HEADER_ADDRESS)) {
            $header = array(
                'version' => self::VERSION,
                'memory_allocation' => $this->memory_allocation,
            );

            if (!shm_put_var($this->shm, self::HEADER_ADDRESS, $header))
                throw new Exception(__METHOD__ . " Failed. Could Not Read Header. If this problem persists, try running the daemon with the --resetworkers option.");
        }

        // Check memory allocation and warn the user if their malloc() is not actually applicable (eg they changed the malloc but used --recoverworkers)
        $header = shm_get_var($this->shm, self::HEADER_ADDRESS);
        if ($header['memory_allocation'] <> $this->memory_allocation)
            $this->log('Warning: Seems you\'ve using --recoverworkers after making a change to the worker malloc memory limit. To apply this change you will have to restart the daemon without the --recoverworkers option.' .
              PHP_EOL . 'The existing memory_limit is ' . $header['memory_allocation'] . ' bytes.');

        // If we're trying to recover previous messages/shm, scan the shared memory block for call structs and import them
        if ($this->daemon->recover_workers()) {
            $max_id = $this->call_count;
            for ($i=0; $i<100000; $i++) {
                if(shm_has_var($this->shm, $i)) {
                    $o = @shm_get_var($this->shm, $i);
                    if (!is_object($o)) {
                        @shm_remove_var($this->shm, $i);
                        continue;
                    }
                    $this->calls[$i] = $o;
                    $max_id = $i;
                }
            }
            $this->log("Starting Job Numbering at $max_id.");
            $this->call_count = $max_id;
        }
    }


    /**
     * Handle IPC Errors
     * @param $error_code
     * @param int $try    Inform ipc_error of repeated failures of the same $error_code
     * @return boolean  Returns true if the operation should be retried.
     */
    protected function ipc_error($error_code, $try=1) {

        $that = $this;
        $is_parent = Core_Daemon::is('parent');

        // Count errors and compare them against thresholds.
        // Different thresholds for parent & children
        $counter = function($type) use($that, $is_parent) {
            static $error_thresholds = array(
                'communication' => array(10,  50), // Identifier related errors: The underlying data structures are fine, but we need to re-create a resource handle (child, parent)
                'corruption'    => array(10,  25), // Corruption related errors: The underlying data structures are corrupt (or possibly just OOM)
                'catchall'      => array(10,  25),
            );

            $that->error_counts[$type]++;
            if ($that->error_counts[$type] > $error_thresholds[$type][(int)$is_parent])
                $that->fatal_error("IPC '$type' Error Threshold Reached");
            else
                $that->log("Incrementing Error Count for {$type} to " . $that->error_counts[$type]);
        };

        // Most of the error handling strategy is simply: Sleep for a moment and try again.
        // Use a simple back-off that would start at, say, 2s, then go to 6s, 14s, 30s, etc
        // Return int
        $backoff = function($delay) use ($try) {
            return $delay * pow(2, min(max($try, 1), 8)) - $delay;
        };

        // Create an array of random, moderate size and verify it can be written to shared memory
        // Return boolean
        $test = function() use($that) {
            $arr = array_fill(0, mt_rand(10, 100), mt_rand(1000, 1000 * 1000));
            $key = mt_rand(1000 * 1000, 2000 * 1000);
            @shm_put_var($that->shm, $key, $arr);
            usleep(5000);
            return @shm_get_var($that->shm, $key) == $arr;
        };

        switch($error_code) {
            case 0:             // Success
            case 4:             // System Interrupt
            case MSG_ENOMSG:    // No message of desired type
                // Ignored Errors
                return true;
                break;

            case MSG_EAGAIN:    // Temporary Problem, Try Again
                usleep($backoff(20000));
                return true;
                break;

            case 22:
                // Invalid Argument
                // Probably because the queue was removed in another process.

            case 43:
                // Identifier Removed
                // A message queue was re-created at this address but the resource identifier we have needs to be re-created
                $counter('communication');
                if (Core_Daemon::is('parent'))
                    usleep($backoff(20000));
                else
                    sleep($backoff(2));

                $this->ipc_create();
                return true;
                break;

            case null:
                // Almost certainly an issue with shared memory
                $this->log("Shared Memory I/O Error at Address {$this->guid}.");
                $counter('corruption');

                // If this is a worker, all we can do is try to re-attach the shared memory.
                // Any corruption or OOM errors will be handled by the parent exclusively.
                if (!Core_Daemon::is('parent')) {
                    sleep($backoff(3));
                    $this->ipc_create();
                    return true;
                }

                // If this is the parent, do some diagnostic checks and attempt correction.
                usleep($backoff(20000));

                // Test writing to shared memory using an array that should come to a few kilobytes.
                for($i=0; $i<2; $i++) {
                    if ($test())
                        return true;

                    // Re-attach the shared memory and try the diagnostic again
                    $this->ipc_create();
                }

                $this->log("IPC DIAG: Re-Connect failed to solve the problem.");

                // Attempt to re-connect the shared memory
                // See if we can read what's in shared memory and re-write it later
                $items_to_copy = array();
                $items_to_call = array();
                for ($i=0; $i<$this->call_count; $i++) {
                    $call = @shm_get_var($this->shm, $i);
                    if (!is_object($call))
                        continue;

                    if (!isset($this->calls[$i]))
                        continue;

                    if ($this->calls[$i]->status == self::TIMEOUT)
                        continue;

                    if ($this->calls[$i]->status == self::UNCALLED) {
                        $items_to_call[$i] = $call;
                        continue;
                    }

                    $items_to_copy[$i] = $call;
                }

                $this->log("IPC DIAG: Preparing to clean SHM and Reconnect...");

                for($i=0; $i<2; $i++) {
                    $this->ipc_destroy(false, true);
                    $this->ipc_create();

                    if (!empty($items_to_copy))
                        foreach($items_to_copy as $key => $value)
                            @shm_put_var($this->shm, $key, $value);

                    if (!$test()) {
                        if (empty($items_to_copy)) {
                            $this->fatal_error("Shared Memory Failure: Unable to proceed.");
                        } else {
                            $this->log('IPC DIAG: Purging items from shared memory: ' . implode(', ', array_keys($items_to_copy)));
                            unset($items_to_copy);
                        }
                    }
                }

                foreach($items_to_call as $call) {
                    $this->retry($call);
                }

                return true;

            default:
                if ($error_code)
                    $this->log("Message Queue Error {$error_code}: " . posix_strerror($error_code));

                if (Core_Daemon::is('parent'))
                    usleep($backoff(20000));
                else
                    sleep($backoff(3));

                $counter('catchall');
                $this->ipc_create();
                return false;
        }
    }

    /**
     * Handle an Error
     * @return mixed
     */
    public function error()
    {
    }
}