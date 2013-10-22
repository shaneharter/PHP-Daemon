<?php

/**
 * Manage creation and shutdown of Worker and Task processes used by the Daemon
 * @author Shane Harter
 */
class Core_Plugin_ProcessManager implements Core_IPlugin
{

    /**
     * The length (in seconds) of the rolling window used to detect process churn
     */
    const CHURN_WINDOW = 120;

    /**
     * The number of failed processes within the CHURN_WINDOW required to trigger a fatal error
     */
    const CHURN_LIMIT = 5;

    /**
     * @var Core_Daemon
     */
    public $daemon;

    /**
     * @var Core_Lib_Process[]
     */
    public $processes = array();

    /**
     * Array of failed forks -- reaped within in expected_min_ttl
     * @var Array   Numeric key, the value is the time the failure occurred
     */
    private $failures = array();



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
        $this->daemon->on(Core_Daemon::ON_IDLE, array($this, 'reap'), 30);
    }

    /**
     * Called on Destruct
     * @return void
     */
    public function teardown() {

        if (!$this->daemon->is('parent'))
            return;

        while($this->count() > 0) {
            foreach($this->processes() as $pid => $process)
                if ($message = $process->stop())
                    $this->daemon->log($message);

            $this->reap(false);
            usleep(250000);
        }

        $this->reap(false);
    }

    /**
     * This is called during object construction to validate any dependencies
     * NOTE: At a minimum you should ensure that if $errors is not empty that you pass it along as the return value.
     * @return Array  Return array of error messages (Think stuff like "GD Library Extension Required" or "Cannot open /tmp for Writing") or an empty array
     */
    public function check_environment(Array $errors = array()) {
        if (! $this->daemon instanceof Core_Daemon)
            $errors[] = "Invalid reference to Application Object";

        return $errors;
    }

    /**
     * Return the number of processes, optionall by $group
     * @param $group
     * @return int
     */
    public function count($group = null) {
        if ($group)
            if (isset($this->processes[$group]))
                return count($this->processes[$group]);
            else
                return 0;

        // Sum processes across all process groups
        $count = 0;
        foreach($this->processes as $process_group)
            $count += count($process_group);

        return $count;
    }

    /**
     * The $processes array is hierarchical by process group. This will return a flat array of processes.
     * @param null $group
     * @return Core_Lib_Process[]
     */
    public function processes($group = null) {
        if ($group)
            if (isset($this->processes[$group]))
                return $this->processes[$group];
            else
                return array();

        // List processes across all process groups
        $list = array();
        foreach($this->processes as $process_group)
            $list += $process_group;

        return $list;
    }

    /**
     * Return a single process by its pid
     * @param $pid
     * @return Core_Lib_Process
     */
    public function process($pid) {
        foreach($this->processes as $process_group)
            if (isset($process_group[$pid]))
                return $process_group[$pid];

        return null;
    }

    /**
     * Fork a new process, optionally within the supplied process $group.
     * @param null $group
     * @return bool|Core_Lib_Process    On failure, will return false. On success, a Core_Lib_Process object will be
     *         returned to the caller in the original (parent) process, and True will be returned to the caller in the
     *         new (child) process.
     */
    public function fork($group = null) {

        $pid = pcntl_fork();
        switch ($pid)
        {
            case -1:
                // Parent Process - Fork Failed
                return false;

            case 0:
                // Child Process
                @ pcntl_setpriority(1);
                $this->daemon->dispatch(array(Core_Daemon::ON_FORK));
                return true;

            default:
                // Parent Process - Return the pid of the newly created Task
                $proc = new Core_Lib_Process();
                $proc->pid = $pid;
                $proc->group = $group;

                if (!isset($this->processes[$group]))
                    $this->processes[$group] = array();

                $this->processes[$group][$pid] = $proc;
                return $proc;
        }
    }

    /**
     * Maintain the worker process map and notify the worker of an exited process.
     * @param bool $block   When true, method will block waiting for an exit signal
     * @return void
     */
    public function reap($block = false) {

        $map = $this->processes();

        while(true) {

            $pid = pcntl_wait($status, ($block === true && $this->daemon->is('parent')) ? NULL : WNOHANG);
            if (!$pid || !isset($map[$pid]))
               break;

            $alias   = $map[$pid]->group;
            $process = $this->processes[$alias][$pid];
            $this->daemon->dispatch(array(Core_Daemon::ON_REAP), array($process, $status));
            unset($this->processes[$alias][$pid]);

            // Keep track of process churn -- failures within a processes min_ttl
            // If too many failures of new processes occur inside a given interval, that's a problem.
            // Raise a fatal error to prevent runaway process forking which can be very damaging to a server
            if ($this->daemon->is('shutdown') || $process->runtime() >= $process->min_ttl)
                continue;

            foreach($this->failures as $key => $failure_time)
                if ($failure_time + self::CHURN_WINDOW < time())
                    unset($this->failures[$key]);

            if (count($this->failures) > self::CHURN_LIMIT)
                $this->daemon->fatal_error("Recently forked processes are continuously failing. See error log for additional details.");
        }
    }

}
