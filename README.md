# PHP Simple Daemon #

Create solid, long-running PHP daemon processes by extending the Core_Daemon class. Use a built-in timer to run your application in second or sub-second frequencies, or build servers using libraries like Socket and LibEvent. Create conventional single-process applications or choose true parallel processing in PHP with persistent background workers.

> Note: For many reasons PHP is not an optimal language choice for creating servers or daemons. I created this library so if you *must* use PHP for these things, you can do it with ease and produce great results. But if you have the choice, Java, Python, Ruby, etc, are all better suited for this. 

#### Requires: ###
* PHP 5.3 or Higher
* A POSIX compatible operating system (Linux, OSX, BSD)
* POSIX and PCNTL Extensions for PHP
 
#### Changes in 2.0:
* Create asynchronous background workers with the new Worker API. See the `PrimeNumbers` example application or the Wiki.
* Hook into a simple callback system using familiar `on(event, callable)` & `off()` syntax. Built-in events give you the ability to hook into application state changes, or make your own to create a simple message bus for your application.
* Create event loops that are not timer-based (build socket-servers, or use blocking system calls with ease)
* Dozens of additional enhancements and bug fixes.

#### Support & Consulting
* Commercial support & consulting is available, including on-site support in the San Francisco Bay Area.
* Contact me through GitHub for more details, including no-cost consultation. 

##Notable Features: 

* ###We provide the event loop: Build your application free of boilerplate.
You start with a working application right out of the box. Once you extend `Core_Daemon` and implement the 3 abstract methods (they can be empty), you have a working prototype. 

* ###Block or Clock: Your choice in 1 LOC
Most daemon applications will either use a blocking API library (libevent, socket_listen, etc), or run on an internal clock with code that needs to be run every 5 seconds, or every second, or 5 times a second. 

  You can implement a clock with 1 line of code or leave it out for an event loop built on async or blocking IO.
  

* ###True parallel processing in PHP 
In a few lines of code you can create asynchronous background processes that let you keep your daemon process light and responsive. After you pass an object to the Worker API, you can call its methods normally. The API silently intercepts your method call and passes it to the object running in the background process. The call returns as soon as the Worker API intercepts it, and your daemon continues normally.  When the background process completes the method call any `onReturn` callbacks you set are fired. And if things go wrong you've got the ability to enforce a timeout and easily retry the call. 

  As an altenative to the persistent, long-running background workers, the Tasks API gives you a simple way to call any method in an ad-hoc background process that will exit when your method is complete. 

  PHP Simple Daemon workers and tasks are simple and powerful multi-processing tools in a language with very few of them. (But don't go trying to build a PHP version of Node.js)

  https://github.com/shaneharter/PHP-Daemon/wiki/Worker-API
  
  https://github.com/shaneharter/PHP-Daemon/wiki/Task-API

* ###Integrated Debugging Tools
Debugging multi-process applications is notoriously painful and several integrated debugging tools are shipped with the library. 

  Since you cannot run an application like this under xdebug or zend debugger, a debug console is provided that lets you set psuedo breakpoints in your code. Your daemon turns into an interactive shell that gives you the ability to proceed or abort as well as a dozen+ commands to figure out exactly what is happening at any given time. Dump function arguments, eval() custom code, print stack traces, the list goes on. 
  
  In addition to the integrated debug console, the `/scripts` directory includes a useful signal_console app: Attach to your daemon and easily send and re-send signals. Checkout the `PrimeNumbers` application for an example of using a signal handler to mimic occassional real-world events. 

  You'll also find the shm_console app that lets you attach to a shared memory address, scan for keys, view them, and even run a `watch` command that prints out a transactional log of creations, updates and deletes.
  
  https://github.com/shaneharter/PHP-Daemon/wiki/Debugging-workers
  
  https://github.com/shaneharter/PHP-Daemon/wiki/Debug-Tools
  
* ###Simple Callbacks: Because decoupled is better.
A simple jQuery-like API lets you add callbacks to daemon lifecycle events (think: startup, teardown, fork, etc) and create your own. Attach an event listener using `on()`, remove it using `off()`, and create your own using `dispatch()`. Like all PHP Simple Daemon APIs it accepts a Closure or any valid PHP Callback. 

  https://github.com/shaneharter/PHP-Daemon/wiki/Using-callbacks-and-custom-events

* ###Simple Plugins: Because code reuse is better.
If you care more about building a reusable component with the ability to execute code during the daemon startup process before your application code is called than you do about decoupling, you can create a Plugin simply by implementing `Core_IPlugin`. Plugins are the easiest way to share code between multiple daemon applications and it can literally be implemented in 3 lines of code. We've got several general-purpose plugins on the drawing board to ship with the Core_Daemon library but currently we're shipping just one. The Ini plugin gives you an easy tool to read and validate any config files you ship with your application.

  https://github.com/shaneharter/PHP-Daemon/wiki/Creating-and-Using-Plugins

* ###Lock files (and lock keys, and lock mutexes, and...)
Several plugins are shipped with the library that implement different ways to create a lock for your daemon process. Running more than once instance of a daemon is often a problem and implementing a locking mechnism is often a headache. We've been paged at 2 AM when supervisord couldn't restart a daemon because of a stale lock file. We've bundled the Lock plugins to try to save you from that same fate. In all cases locks are self-expiring and you can chose between using a Memcache key, a lockfile, or a shared memory address. A faux lock plugin is also shipped to make your life easier during application development. 

* ###Automatic Restart
Applications built with PHP Simple Daemon will automatically restart themselves after catchable fatal errors, and on a user-defined interval as a precaution to combat memory leaks, reinitialize resources, and rotate event logs. Auto-restart is only available when you run your app in "daemon mode" (eg `-d` at the command prompt). You're on your own when you run the app within your shell. 

* ###Built-in Event Logging
The library ships with an insanely basic event log. While the features of off-the-shelf logging libraries are sometimes missed, we have a serious aversion to external dependencies and memory bloat. In any case, the built-in log provider writes messages and headers to a log file you supply. You can see an example of a simple rotator in both example daemons. 

  If you have an internal logging tool, or just a favorite logging library, you can replace the internal tool as simply as overloading the `log()` method. Just be sure the only require parameter is the message being logged and everything internally that uses the event log -- Core_Daemon, signal handlers, the Workers API, etc -- will play nice. 

  https://github.com/shaneharter/PHP-Daemon/wiki/Logging

* ###Built-in Signal Handling
Out of the box, your application will respond to 4 signals. You can add-to or overload that behavior by adding an `ON_SIGNAL` callback. The four built-in behaviors are: 
    
    **SIGINT `kill -2` (or `CTRL+C`):**
    Gracefully shutdown the daemon. Finish the current iteration of the event loop and signal any workers to finish their current tasks. If you're using workers, it could take as long as the maximum worker timeout to complete the process, though the lock file (if one is being used) is released before that so you could re-start the daemon while your workers finish up. 

    **SIGHUP `kill -1`:**
    Gracefully restart the daemon using the process described for SIGINT. Useful after changing the application's code for example. 

    **SIGUSR1 `kill -10`:**
    Dump a block of runtime statistics to the event log or stdout (or both, depending on how you configure logging). Includes things like current uptime, memory usage, event loop busy/idle statistics, and stats for any workers or plugins you have loaded. 

    **SIGCONT `kill -18`:**
    If your daemon is currently blocked or sleeping, wake it up and continue. (Will always wake it up from a sleep(), may not always return from a blocking API call.)
 
    https://github.com/shaneharter/PHP-Daemon/wiki/Creating-Custom-Signal-Handlers
 
* ###Command Line Switches
You can run a '-H' help command when you run the Daemon. It will dump a help menu that looks like this, but can be easily overridden for your daemon:

```
Examples\PrimeNumbers\Daemon
USAGE:
 # run.php -H | -i | -I TEMPLATE_NAME [--install] | [-d] [-p PID_FILE] [--recoverworkers] [--debugworkers]
 
OPTIONS:
 -H Shows this help
 -i Print any daemon install instructions to the screen
 -I Create init/config script
    You must pass in a name of a template from the /Templates directory
    OPTIONS:
     --install
       Install the script to /etc/init.d. Otherwise just output the script to stdout.

 -d Daemon, detach and run in the background
 -p PID_FILE File to write process ID out to

 --recoverworkers
   Attempt to recover pending and incomplete calls from a previous instance of the daemon. Should be run under supervision after a daemon crash. Experimental.

 --debugworkers
   Run workers under a debug console. Provides tools to debug the inter-process communication between workers.
   Console will only be displayed if Workers are used in your daemon
```
