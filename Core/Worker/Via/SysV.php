<?php

class Core_Worker_Via_SysV implements Core_IWorkerVia, Core_IPlugin {

  /**
   * Called on Construct or Init
   * @return void
   */
  public function setup()
  {
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
   * Puts the message on the queue
   * @param $message_type
   * @param $message
   * @return boolean
   */
  public function puts($message_type, $message)
  {
  }

  /**
   * Retrieves a message from the queue
   * @param $message_type
   * @return Array  Returns a call struct.
   */
  public function gets($message_type)
  {
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
  }

  /**
   * Perform any cleanup & garbage collection necessary.
   * @return boolean
   */
  public function garbage_collect()
  {
  }

  /**
   * Drop any pending messages in the queue
   * @return boolean
   */
  public function purge()
  {
  }
}