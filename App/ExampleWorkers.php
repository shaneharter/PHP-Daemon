<?php

class App_ExampleWorkers extends Core_Daemon
{
    protected $loop_interval = 1;

    public $count = 0;


    /**
     * We want to be able to start workers by passing in signals. In a real daemon, workers would be used to process
     * input, handle complex events, etc. To simulate that we're adding listeners for various signals that we can call as desired from the commandline.
     * @var bool
     */
    public $run_primes_among = false;
    public $run_sieve        = false;
    public $run_getfactors   = false;

    protected function load_plugins()
    {
        $this->plugin('Lock_File');

        $this->plugin('Plugin_Ini', array(), 'ini');
        $this->ini->filename = BASE_PATH . '/App/config.ini';
        $this->ini->required_sections = array('example_section');
    }

    protected function load_workers()
    {
        $that = $this;

        // Instantiate an App_Primes object as a Worker
        // Load 3 workers in the pool
        // By convention, workers are named in UpperCase
        $this->worker('PrimeNumbers', new App_Primes());
        $this->PrimeNumbers->timeout(60 * 5);
        $this->PrimeNumbers->workers(3);
        $this->PrimeNumbers->onTimeout(function($call) use($that) {

            $that->log("Job Timed Out!");
            $that->log("Method: " . $call->method);

            if ($call->retries < 3) {
                $that->log("Retrying...");
                $that->example->retry($call);
            } else {
                $that->log("Retries Concluded. I Give Up.");
            }
        });
        $this->PrimeNumbers->onReturn(function($call) use($that) {
            $that->log("Prime Number {$call->method} Complete");
            $that->log("Return: " . implode(', ', $call->return));
        });

        // Add a GetFactors Function as a Named Worker
        $this->worker('GetFactors', function($integer)  {
            if (!is_integer($integer))
                throw new Exception('Invalid Input! Expected Integer. Given: ' . gettype($integer));

            $factors = array();
            for ($i=2; $i<($integer/2); $i++)
                if ($integer % $i == 0)
                    $factors[] = $i;

            return $factors;
        });

        $this->GetFactors->timeout(60 * 5);
        $this->GetFactors->workers(5);
        $this->GetFactors->onReturn(function($call) use($that) {
            $that->log("Factoring Complete for `{$call->args[0]}`");
            $that->log("Factors: " . implode(', ', $call->return));
        });
    }


    protected function setup()
    {
        if ($this->is_parent())
        {
            // Start our workers based on the signals you pass in (-12 and -7, respectively)
            $that = $this; // PHP 5.3 closure hack. Fixed in 5.4
            $this->on(Core_Daemon::ON_SIGNAL, function($signal) use($that) {
                if ($signal == SIGUSR2) {
                    $that->run_sieve = true;
                }
                if ($signal == SIGBUS) {
                    $that->run_getfactors = true;
                }
            });
        }
    }


    protected function execute()
    {
        $this->log('Execute..');

        if (mt_rand(1,50) == 20) {
            $this->run_getfactors = true;
        }

        if (mt_rand(1,50) == 20) {
            $this->run_sieve = true;
        }

        if ($this->run_getfactors) {
            $this->run_getfactors = false;
            $rand = mt_rand(10000, 500000);

            $this->log("Finding Factors of `{$rand}`");
            $this->GetFactors($rand);

        }

        if ($this->run_sieve) {
            $this->run_sieve = false;
            $start = mt_rand(1000, 50000);
            $end = $start + mt_rand(1000, 50000);
            $this->PrimeNumbers->sieve($start, $end);
        }

    }

    protected function log_file()
    {
        $dir = '/var/log/daemons/example';
        if (@file_exists($dir) == false)
            @mkdir($dir, 0777, true);

        if (@is_writable($dir) == false)
            $dir = BASE_PATH . '/example_logs';

        return $dir . '/log_' . date('Ymd');
    }
}
