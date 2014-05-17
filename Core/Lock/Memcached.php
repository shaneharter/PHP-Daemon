<?php

/**
 * Use a Memcached key. The value will be the PID and Memcached ttl will be used to implement lock expiration.
 *
 * @author Shane Harter
 * @since 2011-07-28
 */
class Core_Lock_Memcached extends Core_Lock_Lock implements Core_IPlugin
{
    /**
     * @var Core_Lib_Memcached
     */
  private $memcached = false;

    /**
     * @var array
     */
  public $memcached_servers = array();

  public function setup()
  {
    // Connect to memcached
    $this->memcached = new Core_Lib_Memcached();
    $this->memcached->ns($this->daemon_name);

    // We want to use the auto-retry feature built into our memcached wrapper. This will ensure that the occasional blocking operation on
    // the memcached server doesn't crash the daemon. It'll retry every 1/10 of a second until it hits its limit. We're giving it a 1 second limit.
    $this->memcached->auto_retry(1);

    if ($this->memcached->connect_all($this->memcached_servers) === false)
      throw new Exception('Core_Lock_Memcached::setup failed: Memcached Connection Failed');
  }

  public function teardown()
  {
    // If this PID set this lock, release it
    if ($this->get() == $this->pid)
      $this->memcached->delete(Core_Lock_Lock::$LOCK_UNIQUE_ID);
  }

  public function check_environment(Array $errors = array())
  {
    $errors = array();

    if (!(is_array($this->memcached_servers) && count($this->memcached_servers)))
      $errors[] = 'Memcached Plugin: Memcached Servers Are Not Set';

        if (!class_exists('Memcached'))
            $errors[] = 'Memcached Plugin: PHP Memcached Extension Is Not Loaded';

    if (!class_exists('Core_Lib_Memcached'))
      $errors[] = 'Memcached Plugin: Dependant Class "Core_Lib_Memcached" Is Not Loaded';

    return $errors;
  }

  protected function set()
  {
    $this->memcached->set(Core_Lock_Lock::$LOCK_UNIQUE_ID, $this->pid);
  }

  protected function get()
  {
    $lock = $this->memcached->get(Core_Lock_Lock::$LOCK_UNIQUE_ID);

    return $lock;
  }
}