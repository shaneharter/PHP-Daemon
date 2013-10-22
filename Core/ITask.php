<?php

/**
 * Objects that implement Core_ITask can be passed directly to the Core_Daemon::task() method. Simple tasks can be implemented as 
 * a closure. But more complex tasks (or those that benefit from their own setup() and teardown() code) can be more cleanly
 * written as a Core_ITask object. 
 *
 * The setup() method in a Core_ITask object is run in the newly-forked process created specifically for this task. You can 
 * also create a Core_Daemon::on(ON_FORK) event handler that can run any setup/auditing/tracing/etc code in the parent process. 
 * 
 * Note: An ON_FORK event will be dispatched every time a new task() is created -- whether that is explicitely by you, or implicitely 
 * by the Worker API. 
 */
interface Core_ITask
{
    /**
     * Called on Construct or Init
     * @return void
     */
    public function setup();

    /**
     * Called on Destruct
     * @return void
     */
    public function teardown();

    /**
     * This is called after setup() returns
     * @return void
     */
    public function start();

    /**
     * Give your ITask object a group name so the ProcessManager can identify and group processes. Or return Null
     * to just use the current __class__ name.
     * @return string
     */
    public function group();
}