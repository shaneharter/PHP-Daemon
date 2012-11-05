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


    /**
     * Called on Construct or Init
     * @return void
     */
    public function setup()
    {
        // TODO: Implement setup() method.
    }

    /**
     * Called on Destruct
     * @return void
     */
    public function teardown()
    {
        while($this->is('parent') && count($this->process_map()) > 0) {
            foreach(self::$processes as $worker => $pids)
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
}
