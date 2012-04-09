<?php
/**
 * Created by JetBrains PhpStorm.
 * User: shane
 * Date: 4/8/12
 * Time: 6:27 PM
 * To change this template use File | Settings | File Templates.
 */
class Core_Worker_Mediator
{
    public $function;
    public $object;

    public function setObject($o) {
        if (!($worker instanceof Core_IWorkerInterface)) {
            throw new Exception("Baaaad Worker");
        }

        $this->object = $o;
    }

    public function setFunction($f) {
        $this->function = $f;
    }






    public function __call($name, $args) {


    }

    public function __get($name) {


    }

    public function __invoke()
    {
        return $this->execute();
    }
}
