<?php
require_once 'config.php';
/**
 * Test case for resource leaks on the automatic restart of the Daemon.
 * Check for open files using 'lsof handledeamontest*.log'
 * The main log will be open two times, one inherited from the first restart.
 * The new logfile in the restarted process will get handle id 0, 
 * that will be considered STDIN and be closed before the next restart. 
 * Therefore we only see two open logfiles.
 * 
 * The amount of other open log files will graddaly increase. 
 * 
 */
class HandleTestDeamon extends \Core_Daemon {
    
    /** use long interval to allow for restart.*/
    protected $loop_interval = 10;

    /**
     * Exeucte method, fails on second try.
     * @throws \Exception
     */
    protected function execute() {
        static $count=0;
        $count++;
        $this->log("execute start $count");      
        $this->openfiles();
        //Need a minum time alive to force a restart.
        if ($count>1) {
            $this->log("execute fail $count");
            throw new \Exception("FAIL");
        }
    }
    
    /**
     * Open a set of files and store them in a static to cause a handle leak.
     * @staticvar type $files
     * @param type $max
     */
    protected function openfiles($max = 5)
    {
        static $files;
        if (empty($files)) {
            $this->log("opening files");
            for ($i=0;$i<$max;$i++) {
                $f = fopen("./handledeamontest".$i.".log", "a+");
                fwrite($f, "test file pid=".getmypid()."\n");
                fflush($f);
                $files[]=$f;
            }
        }
    }

    protected function log_file() {
        //Reuse the same logfile
        return "./handledeamontest.log";
    }

    protected function setup() {     
    }
}

HandleTestDeamon::getInstance()->run();


