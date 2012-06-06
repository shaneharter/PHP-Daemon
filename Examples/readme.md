###PrimeNumbers Example###
* A daemon application that computes factors and finds prime numbers in a given range.
* Uses 2 background workers to perform the calculation without blocking the application's event loop.
* The PrimeNumbers worker is implemented as a Core_IWorker class that exposes several methods such as `is_prime` and `primes_among`.
* The GetFactors worker is implemented as a simple closure that is passed to the workers API.
* Both workers use several instances -- 4 background PrimeNumbers processes and 2 background GetFactors processes are created.
* Demonstrates the ease of using events listeners: Implements a custom signal handler so you can call Worker methods by sending signals to simulate real-world event driven behavior.
* Logs all jobs and their return statuses to a MySQL table (the schema is defined in `config/db.sql`).

###LongPoll Example###
* Uses a single background worker to continuously update the application. The background worker polls an API. When the call returns, your daemon
is updated with the API results and the system immediately begins the next API poll. There's always an API call running in the background to update the daemon.
* Uses the INI plugin to store API details and the `API` class demonstrates accessing the INI plugin from a worker.

###Tasks Example###
* Demonstrates the ability to do ad-hoc jobs in parallel by simply passing a callable to the `task()` method. Unlike persistent background workers,
tasks are run individually in their own process. When the callable you passed-in completes, the process exits.