<?php

/**
 * Plugins have the advantage that their object lifecycle is managed by the Core_Daemon application object.
 * They are instantiated early, before workers are created and before the event loop starts.
 *
 * All plugins are passed a reference to the Core_Daemon application object when they are instantiated.
 */
interface Core_IPlugin
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
     * This is called during object construction to validate any dependencies
     * NOTE: At a minimum you should ensure that if $errors is not empty that you pass it along as the return value.
     * @return Array  Return array of error messages (Think stuff like "GD Library Extension Required" or "Cannot open /tmp for Writing") or an empty array
     */
    public function check_environment(Array $errors = array());
}