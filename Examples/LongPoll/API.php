<?php
/**
 * Created by JetBrains PhpStorm.
 * User: shane
 * Date: 6/1/12
 * Time: 10:43 AM
 * To change this template use File | Settings | File Templates.
 */
class API implements Core_IWorker
{
    /**
     * Provided Automatically
     * @var Core_Worker_Mediator
     */
    public $mediator;

    /**
     * API Endpoint
     * @var String
     */
    private $uri;

    /**
     * API Username
     * @var String
     */
    private $username;

    /**
     * API Token
     * @var String
     */
    private $token;

    /**
     * Array of results
     * @var array
     */
    private $results = array();

    /**
     * Called on Construct or Init
     * @return void
     */
    public function setup()
    {
        // Read API details from the INI file
        // The ini plugin is created in the Poller::setup() method
        $ini = $this->mediator->daemon('ini');
        $this->uri      = $ini['api']['uri'];
        $this->username = $ini['api']['username'];
        $this->token    = $ini['api']['token'];
    }

    /**
     * Called on Destruct
     * @return void
     */
    public function teardown()
    {

    }

    /**
     * Poll the API for updated information -- Simulate an API call of varying duration.
     * @return Array    Return associative array of results
     */
    public function poll()
    {

        static $calls = 0;
        $calls++;

        // Simulate an API call of varying length
        $rand = mt_rand(1,10);
        switch($rand) {
            case 1:
                $ttl = 4;  break;
            case 2:
            case 3:
                $ttl = 8;  break;
            case 8:
            case 9:
                $ttl = 16; break;
            case 10:
                $ttl = 20; break;
            default:
                $ttl = 12;
        }

        // Sleep for $ttl seconds to simulate API request time
        sleep($ttl);

        // If this is our first call, create initial results
        if ($calls == 1) {
            $this->results['customers'] = mt_rand(100,1000);
            $this->results['sales'] = $this->results['customers'] * mt_rand(20,100);
            return $this->results;
        }

        // Increase the stats in our results array accordingly
        $multiplier = mt_rand(100, 125) / 100;
        $this->results['customers'] *= $multiplier;
        $this->results['sales'] *= $multiplier;

        return $this->results;
    }

}
