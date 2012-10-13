<?php

interface Core_IWorkerVia
{

  /**
   * Puts the message on the queue
   * @param $message_type
   * @param $message
   * @return boolean
   */
  public function put($message);

  /**
   * Retrieves a message from the queue
   * @param $message_type
   * @return Array  Returns a call struct.
   */
  public function get($message_type, $blocking = false);

  /**
   * Returns the last error message: poll after a puts or gets failure.
   * @return mixed
   */
  public function get_last_error();

    /**
     * Handle an Error
     * @return mixed
     */
    public function error();

  /**
   * The state of the queue -- The number of pending messages, memory consumption, errors, etc.
   * @return Array with some subset of these keys: messages, memory_allocation, error_count
   */
  public function state();

  /**
   * Perform any cleanup & garbage collection necessary.
   * @return boolean
   */
  public function garbage_collector();

  /**
   * Drop any pending messages in the queue
   * @return boolean
   */
  public function purge();


}