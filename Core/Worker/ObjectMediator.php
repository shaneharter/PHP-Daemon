<?php
/**
 * Created by JetBrains PhpStorm.
 * User: shane
 * Date: 4/8/12
 * Time: 6:27 PM
 * To change this template use File | Settings | File Templates.
 */
class Core_Worker_ObjectMediator
{
    const WORKER_CALL = 1;
    const WORKER_RUNNING = 2;
    const WORKER_RETURN = 3;

    const UNCALLED = 0;
    const CALLED = 1;
    const RUNNING = 2;
    const RETURNED = 3;
    const TIMEOUT = 10;

    private $daemon;
    private $object;
    private $methods = array();
    private $processes = array();
    private $calls = array();
    private $running_calls = array();
    private $shutdown = false;
    private $alias = '';
    private $class;

    private $queue;
    private $shm;

    private $workers = 1;

    private $timeout = 0;
    private $on_return;
    private $on_timeout;
    private $is_parent = true;

    /**
     * The ID of this worker pool -- used to
     * @var int
     */
    private $id;

    public function __construct($alias, Core_Daemon $daemon) {
        $this->alias = $alias;
        $this->daemon = $daemon;
        do {
            $this->id = mt_rand(999999, 9999999);
        } while(msg_queue_exists($this->id) == 1);
    }

    public function setObject($o) {
        if (!($o instanceof Core_IWorkerInterface)) {
            throw new Exception(__METHOD__ . " Failed. Worker objects must implement Core_IWorkerInterface");
        }
        $this->object = $o;
        $this->class = get_class($o);
        $this->methods = get_class_methods($this->class);
    }

    public function setup() {
        $this->queue = msg_get_queue($this->id, 0666);
        $this->shm = shm_attach($this->id, 1024 * 1000, 0666);

        if ($this->is_parent) {
            $this->fork();
        } else {
            $this->daemon->on(Core_Daemon::ON_SIGNAL, array($this, 'signal'));
            $this->log('Worker Process Started...');
        }
    }

    public function fork() {
        while(count($this->processes) < $this->workers) {
            $pid = $this->daemon->fork(array($this, 'start'), array(), true);
            $this->processes[$pid] = microtime(true);
        }
    }

    public function reap($pid) {
        unset($this->processes[$pid]);
    }

    public function start() {

        $this->is_parent = false;
        $this->setup();

        while($this->shutdown == false) {
            $message_type = $message = $message_error = null;
            if (msg_receive($this->queue, self::WORKER_CALL, $message_type, 1024 * 1000, $message, true, 0, $message_error)) {

                try {
                    $call_id = $this->message_decode($message);
                    $call = $this->calls[$call_id];

                    $call->pid = getmypid();
                    if ($this->message_encode($call_id) !== true) {
                        $this->log("Call {$call_id} Could Not Ack Running.");
                    }

                    $callback = array($this->object, $call->method);
                    $call->return = call_user_func_array($callback, $call->args);

                    if ($this->message_encode($call_id) !== true) {
                        $this->log("Call {$call_id} Could Not Ack Complete.");
                    }
                }
                catch (Exception $e) {

                }


            } else {
                $this->log('Message queue error code: ' . $message_error, true);
            }

        }
    }

    public function run() {

        $message_type = $message = $message_error = null;
        if (msg_receive($this->queue, self::WORKER_RUNNING, $message_type, 1024 * 1000, $message, true, MSG_IPC_NOWAIT, $message_error)) {
            $call_id = $this->message_decode($message);
            $this->running_calls[$call_id];
        } else {
            $this->log('Message queue error code: ' . $message_error, true);
        }

        $message_type = $message = $message_error = null;
        if (msg_receive($this->queue, self::WORKER_RETURN, $message_type, 1024 * 1000, $message, true, MSG_IPC_NOWAIT, $message_error)) {
            $call_id = $this->message_decode($message);
            $call = $this->calls[$call_id];

            unset($this->running_calls[$call_id]);
            if (is_callable($this->on_return))
                $this->on_return($call->return);

        } else {
            $this->log('Message queue error code: ' . $message_error, true);
        }

        if ($this->timeout > 0) {
            $now = microtime(true);
            foreach(array_keys($this->running_calls) as $call_id) {
                $call = $this->calls[$call_id];
                if ($now > ($call->time[self::RUNNING] + $this->timeout)) {
                    posix_kill($call->pid, SIGKILL);
                    unset($this->running_calls[$call_id]);
                    $call->status = self::TIMEOUT;

                    if (is_callable($this->on_timeout))
                        $this->on_timeout($call);
                }
            }

        }

    }

    public function signal($signal) {
        switch ($signal)
        {
            case SIGINT:
            case SIGTERM:
                $this->log('Shutdown Signal Received');
                $this->shutdown = true;
                break;
        }
    }

    private function message_encode($call_id) {

        $call = $this->calls[$call_id];

        $queue_lookup = array(
            self::CALLED    => self::WORKER_CALL,
            self::RUNNING   => self::WORKER_RUNNING,
            self::RETURNED  => self::WORKER_RETURN
        );

        $message = array('call' => $call->id);
        $message_error = null;

        $call->status++;
        $call->time[$call->status] = microtime(true);
        shm_put_var($this->shm, $call_id, $call);

        if (msg_send($this->queue, $queue_lookup[$call->status], $message, true, false, $message_error)) {
            return true;
            $this->log("Message Sent to Queue " . $queue_lookup[$call->status]);
        }

        $this->log(__METHOD__ . " Failed. Error: {$message_error}");
        return $message_error;
    }

    private function message_decode(Array $message) {

        $call = null;
        if ($call_id = $message['call'])
            $call = shm_get_var($this->shm, $call_id);

        if (!is_object($call))
            throw new Exception(__METHOD__ . " Failed. Expected stdClass object in {$this->id}:{$call_id}. Given: " . gettype($call));

        $this->calls[$call_id] = $call;
        shm_remove_var($this->shm, $call_id);
        return $call_id;
    }


    private function log($message, $is_error = false) {
        $this->daemon->log("Worker [{$this->alias}] $message\n", $is_error);
    }


    public function __call($method, $args) {
        if (!in_array($method, $this->methods))
            throw new Exception(__METHOD__ . " Failed. Method `{$method}` is not callable.");

        // @todo handle forking

        $call = new stdClass();
        $call->method        = $method;
        $call->args          = $args;
        $call->status        = self::UNCALLED;
        $call->time          = array(microtime(true));
        $call->pid           = null;
        $call->id = count($this->calls);
        $this->calls[$call->id] = $call;

        // @todo handle call errors intelligently...
        return $this->message_encode($call->id) === true;
    }

    public function __invoke($args)
    {
        return $this->__call('execute', $args);
    }

    public function onTimeout($on_timeout)
    {
        if (!is_callable($on_timeout))
            throw new Exception(__METHOD__ . " Failed. Callback or Closure expected.");

        $this->on_timeout = $on_timeout;
    }

    public function onReturn($on_return)
    {
        if (!is_callable($on_return))
            throw new Exception(__METHOD__ . " Failed. Callback or Closure expected.");

        $this->on_return = $on_return;
    }

    public function timeout($timeout)
    {
        if (!is_numeric($timeout))
            throw new Exception(__METHOD__ . " Failed. Numeric timeout value expected.");

        $this->timeout = $timeout;
    }

    public function workers($workers)
    {
        if (!is_numeric($workers))
            throw new Exception(__METHOD__ . " Failed. Numeric workers value expected.");

        $this->workers = $workers;
    }
}
