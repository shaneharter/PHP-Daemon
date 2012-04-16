<?php

class ExampleWorkers_Daemon extends Core_Daemon
{
    protected $loop_interval = 3;

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

        // This daemon will respond to signals sent from the commandline.
        // 1) You can send a signal that will calculate factors of a random number
        // 2) You can send a signal that will find primes within a random range.
        // The signals themselves are defined in the settings.ini
        // We also have other various settings defined in the ini, so we validate that the ini has both [signals] and [default] section

        $this->plugin('settings', new Core_Plugin_Ini());
        $this->settings->filename = BASE_PATH . '/ExampleWorkers/settings.ini';
        $this->settings->required_sections = array('signals', 'default');
    }

    protected function load_workers()
    {
        // PHP 5.3 Closure Hack. Fixed in 5.4.
        $that = $this;

        // Instantiate an App_Primes object as a Worker
        // Load 3 workers in the pool
        // Allocate 256k of shared memory to pass args to the workers and receive results back: If you omit this, it will use 1MB by default.
        // By convention, workers are named in UpperCase
        // Look at App_Prime to see the available methods. They are: sieve, is_prime, primes_among

        $this->worker('PrimeNumbers', new ExampleWorkers_Workers_Primes());
        $this->PrimeNumbers->timeout(60 * 5);
        $this->PrimeNumbers->workers(3);
        $this->PrimeNumbers->malloc(1024 * 256);

        $this->PrimeNumbers->onReturn(function($call) use($that) {
            $that->log("Prime Number {$call->method} Complete");

            switch($call->method) {
                case "sieve":
                    $that->log( sprintf('Return: There are %s items in the resultset, from %s to %s.', count($call->return), $call->return[0], $call->return[count($call->return)-1])  );
                    break;

                case "primes_among":
                    $that->log(sprintf('Return. Among [%s], Primes Are [%s]', implode(', ', $call->args[0]), implode(', ', $call->return)));
            }

        });

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



        // Add a GetFactors Function as a Named Worker
        // It will accept a single integer and return all of its factors.
        // In the Return handler, we are using the PrimeNumbers worker to return all the items from the getFactors result that are also prime numbers.
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

            $that->log("Finding Prime Factors...");
            $that->PrimeNumbers->primes_among($call->return);
        });


    }


    protected function setup()
    {
        if ($this->is_parent())
        {
            // We want to be able use signals to interact with the example daemon, so we can test and demonstrate
            // the workers. Load a configurable signal map from the loaded ini plugin

            $that = $this;
            $this->on(Core_Daemon::ON_SIGNAL, function($signal) use($that) {
                if (isset($that->settings['signals'][$signal])) {
                    $action = $that->settings['signals'][$signal];
                    $that->log("Signal Received! Setting {$action}=true");
                    $that->{$action} = true;
                }
            });

            // You never really want to call a worker method directly from a signal handler.
            // This is because signal handlers are not re-entrant. So worker processes initiated within a signal handler
            // will not respond to any signals themselves. So here we're setting a flag that is polled in the execute() method.
        }
    }


    protected function execute()
    {
        // Run our Factor and Prime workers randomly and in response to signals
        switch (mt_rand(1, 50)) {
            case 20:
            case 40:
                $this->run_getfactors = true;
                break;

            case 10:
            case 30:
                $this->run_sieve = true;
                break;
        }

        if ($this->run_getfactors) {
            $this->run_getfactors = false;
            $rand = mt_rand(100000, 1000000);
            $this->log("Finding Factors of `{$rand}`");
            $this->GetFactors($rand);
        }

        if ($this->run_sieve) {
            $this->run_sieve = false;
            $rand = mt_rand(10000, 1000000);
            $this->PrimeNumbers->sieve($rand, $rand + $rand);
        }
    }

    protected function log_file()
    {
        $dir = '/var/log/daemons/exampleworkers';
        if (@file_exists($dir) == false)
            @mkdir($dir, 0777, true);

        if (@is_writable($dir) == false)
            $dir = BASE_PATH . '/logs';

        return $dir . '/log_' . date('Ymd');
    }
}
