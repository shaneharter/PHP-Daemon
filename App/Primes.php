<?php
/**
 * Fun with Prime Numbers
 * PHP Simple Daemon Worker
 * @author Shane Harter
 */
class App_Primes implements Core_IWorkerInterface
{
    /**
     * Reference to the mediator is automatically provided
     * @var Core_Worker_Mediator
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

    function primes_among($set) {
        $primes = array();
        foreach($set as $i)
            if ($this->is_prime($i))
                $primes[] = $i;

        return $primes;
    }

    function is_prime($number) {

    }

    function sieve($start, $end) {
        $this->mediator->log("Searching for Prime Numbers between {$start} and {$end}");
        $primes = array();
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
