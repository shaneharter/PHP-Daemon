<?php

interface Core_IWorkerVia
{

  /**
   * Puts the message on the queue
   * @param $message_type
   * @param $message
   * @return boolean
   */
  public function puts($message_type, $message);

  /**
   * Retrieves a message from the queue
   * @param $message_type
   * @return Array  Returns a call struct.
   */
  public function gets($message_type);

  /**
   * Returns the last error: poll after a puts or gets failure.
   * @return mixed
   */
  public function get_last_error();

  /**
   * The state of the queue -- The number of pending messages, memory consumption, errors, etc.
   * @return Array with some subset of these keys: messages, memory_allocation, error_count
   */
  public function state();

  /**
   * Perform any cleanup & garbage collection necessary.
   * @return boolean
   */
  public function garbage_collect();

}