<?php

namespace Examples\PrimeNumbers;

/**
 * Fun with Prime Numbers
 * PHP Simple Daemon Worker
 * @author Shane Harter
 */
class Workers_Primes implements \Core_IWorker
{
    /**
     * Reference to the mediator is automatically provided
     * @var \Core_Worker_Mediator
     */
    public $mediator;

    /**
     * Called on Construct or Init
     * @return void
     */
    public function setup()
    {
        // Satisfy Interface
    }

    /**
     * Called on Destruct
     * @return void
     */
    public function teardown()
    {
        // Satisfy Interface
    }

    /**
     * This is called during object construction to validate any dependencies
     * @return Array    Return array of error messages (Think stuff like "GD Library Extension Required" or "Cannot open /tmp for Writing") or an empty array
     */
    public function check_environment()
    {
        // Satisfy Interface
    }

    public function primes_among($set) {
        $this->mediator->log('Looking for primes among ' . count($set) . ' items between ' . min($set) . ' and ' . max($set));
        $primes = array();
        foreach($set as $i)
            if ($this->is_prime($i))
                $primes[] = $i;

        return $primes;
    }

    public function someDebugFunction($arg1, $arg2) {
        echo "This is an example function I created that I'll use in the debug console. Demonstrates the ability to use",
             "the debug console and its automatic breakpoints, custom code like this, and the ability to dynamically",
             "inject code using eval() to debug multi-process Daemon/Worker applications.";

        // If I return true, the current breakpoint in the debug console will be continued-from. Otherwise
        // I'll stay at the breakpoint after this returns.
        return ($arg1 > $arg2);
    }

    public function is_prime($number) {

        $result = $this->sieve($number, $number);
        return !empty($result);
    }

    public function sieve($start, $end) {
        $primes = array();

        // The sieve is designed to work from 3 and above.
        // We know that "2" is a Prime. So if $start is less than "3", we will prepend the array with "2".
        // But in some cases, "1" is considered prime, and in others not. For our purposes, we've put a setting in the ini file to resolve this issue.
        // The daemon() method on the mediator allows us to import objects/properties from the daemon by name
        if ($start < 3) {
            $settings = $this->mediator->daemon('settings');
            if ($start < 1 && $settings['default']['is_one_prime'])
                $primes = array(1, 2);
            else
                $primes = array(2);

            $start = 3;
        }

        // This is an example of how you can add in custom breakpoints that will be active when you run
        // your daemon with the --debugworkers flag set.
        $this->mediator->prompt("Something Is Happeneing in sieve! Oh Noes!", array($start, $end));

        for ($i = $start; $i <= $end; $i++)
        {
            if($i % 2 != 1)
                continue;

            $d = 3;
            $x = sqrt($i);
            while ($i % $d != 0 && $d < $x)
                $d += 2;

            if((($i % $d == 0 && $i != $d) * 1) == 0)
                $primes[] = $i;
        }
        return $primes;
    }
}
