<?php
/**
 * Adapt a supplied object to the Worker Mediator
 * @see https://github.com/shaneharter/PHP-Daemon/wiki/Named-Workers
 * @author Shane Harter
 */
final class Core_Worker_ObjectMediator extends Core_Worker_Mediator
{

    /**
     * @var Core_IWorkerInterface
     */
    protected $object;

    /**
     * The mediated $object's class
     * @var
     */
    protected $class;


    public function __destruct() {
        if (is_object($this->object))
            $this->object->teardown();
        parent::__destruct();
    }

    public function setObject($o) {
        if (!($o instanceof Core_IWorkerInterface)) {
            throw new Exception(__METHOD__ . " Failed. Worker objects must implement Core_IWorkerInterface");
        }
        $this->object = $o;
        $this->object->mediator = $this;
        $this->class = get_class($o);
        $this->methods = get_class_methods($this->class);
    }

    public function setup() {

        if (!$this->is_parent) {
            $this->object->setup();
        }
        parent::setup();
    }

    public function check_environment() {
        $errors = array();

        if (!is_object($this->object) || !$this->object instanceof Core_IWorkerInterface)
            $errors[] = 'Invalid worker object. Workers must implement Core_IWorkerInterface';

        $object_errors = $this->object->check_environment();
        if (is_array($object_errors))
            $errors = array_merge($errors, $object_errors);

        return parent::check_environment($errors);
    }

    protected function getCallback(stdClass $call) {
        return array($this->object, $call->method);
    }


    /**
     * Return an instance of $object, allowing inline (synchronous) calls that bypass the mediator.
     * Useful if you want to call methods in-process for some reason.
     * Note: Timeouts will not be enforced
     * Note: Your daemon event loop will be blocked until your method calls return.frr
     * @example Your worker object returns data from a webservice, you can put methods in the class to format the data.
     *          In that case you can call it in-process for brevity and convenience.
     * @example $this->DataService->inline()->pretty_print($result);
     * @return Core_IWorkerInterface
     */
    public function inline() {
        return $this->object;
    }





}
