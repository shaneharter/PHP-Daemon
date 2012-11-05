<?php

class ProcessManager implements Core_IPlugin
{
    /**
     * @var Core_Daemon
     */
    public $daemon;

    /**
     * @var Core_Lib_Process[]
     */
    public $processes;


    public function __construct(Core_Daemon $daemon) {
        $this->daemon = $daemon;
    }

    /**
     * Called on Construct or Init
     * @return void
     */
    public function setup() {


    }

    public function count() {
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

        $list = array();
        foreach($this->processes as $process_group)
            $list += $process_group;

        return $list;
    }

    /**
     * Called on Destruct
     * @return void
     */
    public function teardown()
    {
        if (!$this->daemon->is('parent'))
            return;

        while($this->count() > 0) {


            foreach($this->processes() as $pid => $process)
                if ($message = $process->stop())
                    $this->daemon->log($message);


                if (count($pids))
                    $this->{$worker}->teardown();

            $this->reap(false);
            usleep(50000);
        }

        $this->reap(false);
    }

    /**
     * This is called during object construction to validate any dependencies
     * NOTE: At a minimum you should ensure that if $errors is not empty that you pass it along as the return value.
     * @return Array  Return array of error messages (Think stuff like "GD Library Extension Required" or "Cannot open /tmp for Writing") or an empty array
     */
    public function check_environment(Array $errors = array())
    {
        if (! $this->daemon instanceof Core_Daemon)
            $errors[] = "Invalid reference to Application Object";

        return $errors;
    }



    /**
     * When a signal is sent to the process it'll be handled here
     * @param integer $signal
     * @return void
     */
    public function signal($signal)
    {
        switch ($signal)
        {
            case SIGCHLD:
                break;
        }
    }
}
