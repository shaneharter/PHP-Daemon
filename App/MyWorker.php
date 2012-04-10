<?php
/**
 * Created by JetBrains PhpStorm.
 * User: shane
 * Date: 4/9/12
 * Time: 4:22 PM
 * To change this template use File | Settings | File Templates.
 */
class App_MyWorker implements Core_IWorkerInterface
{
    private $daemon;
    public function __construct($d) {
        $this->daemon = $d;
    }

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
        // TODO: Implement teardown() method.
    }

    /**
     * This is called during object construction to validate any dependencies
     * @return Array    Return array of error messages (Think stuff like "GD Library Extension Required" or "Cannot open /tmp for Writing") or an empty array
     */
    public function check_environment()
    {
        // TODO: Implement check_environment() method.
    }


    public function doooit($count, $value) {
        $this->daemon->log("So TyTy... Going to Sleep. Job Number $count. You know what I love? $value[2]");
        for ($i=0; $i<10000000; $i++) {

        }
        $this->daemon->log("Awake!");
    }

}
