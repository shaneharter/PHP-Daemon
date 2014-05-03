<?php

/**
 * Use a lock file. The PID will be set as the file contents, and the filemtime will be used to determine
 * expiration.
 *
 * @author Shane Harter
 * @since 2011-07-29
 */
class Core_Lock_File extends Core_Lock_Lock implements Core_IPlugin
{
    /**
     * The directory where the lockfile will be written. The filename will be whatever you set the $daemon_name to be.
     * To use the current directory, define and use a BASE_PATH constant: Using ./ will fail when the script is
     * run from crontab.
     *
     * @var string  A filesystem path
     */
    public $path = '';

    protected $filename;

    public function __construct(Core_Daemon $daemon, array $args = array())
    {
        parent::__construct($daemon, $args);
        if (isset($args['path']))
            $this->path = $args['path'];
        else
            $this->path = dirname($daemon->get('filename'));
    }

    public function setup()
    {
        if (substr($this->path, -1, 1) != '/')
            $this->path .= '/';

        $this->filename = $this->path . $this->daemon_name . '.' . Core_Lock_Lock::$LOCK_UNIQUE_ID;
    }

    public function teardown()
    {
        // If the lockfile was set by this process, remove it. If filename is empty, this is being called before setup()
        if (!empty($this->filename) && $this->pid == $this->get())
            @unlink($this->filename);
    }

    public function check_environment(array $errors = array())
    {
        if (!is_writable($this->path))
            $errors[] = 'Lock File Path ' . $this->path . ' Not Writable.';

        return $errors;
    }

    public function set()
    {
        // The lock value will contain the process PID
        file_put_contents($this->filename, $this->pid);

        touch($this->filename);
    }

    protected function get()
    {
        if (!file_exists($this->filename))
            return false;

        $lock = file_get_contents($this->filename);

        return $lock;
    }
}