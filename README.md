# PHP Simple Daemon #

Create solid, long-running PHP daemon processes by extending this Core_Daemon base class. A unique feature and a key idiom of PHP Simple Daemon is a built-in timer. Your subclass contains an execute() method that is called by the internal timer at the interval you specify -- whether that's one hour, one second, or 1/10 of a second. 

I've built and deployed a large number of mission-critical daemons using this library.


#### Requires: ###
* PHP 5.3 or Higher
* POSIX and PCNTL Extensions for PHP
 
#### VERSION 2.0 BETA AVAILABLE
* A Beta version 2.0 is now available: Checkout the `named-workers` branch and start submitting Issues and Pull Requests!
* The primary feature of this release is **persistant, interactive, asynchronous background workers**
  * Create classes or functions that automatically detach themselves from your Daemon and run in their own background process. 
  * Easily pass arguments to these functions/methods, and return work back to the daemon process. 
  * Create common async patterns by implementing onReturn and onTimeout callbacks so you know when work was done (or not).
  * Create worker pools: Run 5 simultaneous worker objects as easily as $this->myWorker->workers(5). Work will be assigned to idle workers automatically. 
  * If all workers are busy, calls are buffered and are handed-out to workers as they become available. 
  * Set timeouts on workers as easily as $this->myWorker->timeout(30); // 30 Seconds. 
  * For a very brief example, try out the /App/ExampleWorkers.php daemon in the named-workers checkout. 
  * Note: The latest builds include a bug that leaves the daemon and child processes hanging on shutdown and it requires a `kill -9 [pid]`. I hope to have this fixed in the next day or so. 
  * Note: This will be explained better in the Wiki, but when workers are busy, calls are *buffered* not *queued*. If the daemon is killed, buffered calls *may* be lost. There is no processing guarantee. For that, there are fantastic message queues that work really well with PHP Simple Daemon workers. 
* The version also includes dozens of enhancements to existing daemon features, such as: 
  * The Locking mechanism is now optional and is implemented entirely using the Plugin architecture 
  * You can easily choose to use the classic, clock-based event loop (eg 'run this every 1.5 seconds') or a lighter-weight event loop that iterates immediately. 
  * You can attach to Daemon state events using the familiar on(SOME_EVENT, $callback) syntax. 
  * Use the Event system to capture and handle custom Signals in your code. 
  * Self-adjusting process priority to be more reliable when used with short loop_intevals (1 second and below)
  * Automatically generate and install init.d scripts to easily install your daemon 
  * Track and report runtime stats, such as mean interval duration. 
  * Documentation improvements on the Wiki.
* This is certainly BETA quality software. I hope to have a thoroughly-tested, production-ready version by May 1st. 

#### Changes in 1.1.1:
* Several Bugfixes
* Add simple "Installation Instructions" feature: Pass -i to your daemon to print out simple installation instructions (crontab entries, file permissions, etc). Easily define these instructions in your daemon by adding messages to an array. 

#### Changes in 1.1.0: 
* Add a simple Plugin interface.
* Simplify Core_Daemon by rewriting the Lock mechanism (previously named 'heartbeat') and INI file management as Plugins. 
* Add a File-based locking plugin as an aleternative to Memcache, eliminating the Memcached dependency. 
* Add a Null "mock" lock provider for testing or use in cases when multiple running instances is desired. 
* Fix a race condition bug with the Locking mechanism. 


##Notable Features: 

* ###Built-In while() loop and micro-time timer
Example: You set "->loop_interval=1". The PHP-Daemon will call your execute() method and time it. Suppose it takes 0.2 seconds. Upon its completion, the timer will sleep for the remaining 8/10 second. It wakes-up and then iterates. If your execute() method does not return before the end of the loop_interval (1 second in this case), an error will be logged. But since the execute() method is blocking, the next iteration will not begin until the first is complete. 

* ###Braindead-Simple Forking for tasks that can be parallelized
Suppose your execute() method needed to push its results to an external API. If your interval is at 1 second you just don't have enough time to use an external resource. In these instances, PHP-Daemon provides a fork() method.  It accepts a callback (todo: a Closure). When called, it spawns a child process, executes the callback, and exits. You have to be careful, dozens of long-running, possibly hung child processes is not good. But when used carefully it gives you a very simple, very powerful tool and you don't have to worry about mastering the idiosyncrasies of PHP forking. 

    https://github.com/shaneharter/PHP-Daemon/wiki/Forking-Example

* ###Auto Restart
No matter how diligent you are, memory bloat can occur. PHP-Daemon is able to auto-restart itself both as an attempt to recover from a fatal error, and on a user-defined interval to combat memory bloat. Only available when running in "Daemon mode" (-d at the command prompt), the built-in timer will track total runtime and when it hits the threshold you've set in the ini file, it will perform a graceful restart. 

* ###Signal Handling
By default PHP-Daemon listens for 3 signals: SIGINT, SIGHUP, SIGUSR1. When you send the Daemon a standard 'kill [pid]' SIGINT, it will do an graceful shutdown: It will finish the current iteration of the run loop and then shutdown. If you send a 'kill -1 [pid]' HUP command, it will trigger the auto-restart feature. And if you send a 'kill -10 [pid]' SIGUSR1, it will respond by dumping a block of runtime variables to either the log or stdout or both, depending on how you configure logging. 

* ###Process Locking
A lock is created when your daemon starts that will prevent additional, errant instances of the daemon from starting. Currently, File and Memcached lock providers are available. By using the Memcached lock provider you can even restrict the daemon to a single instance across a cluster of servers. In situations of maximum importance that the daemon stay running, you could deploy the daemon across a cluster, and add crontab entries to start the daemon every minute on each box. The memcache lock key will be instantly polled by each new instance and it will shut itself down if a lock exists. 
    
    During development, a Null lock provider can be used to emulate locking behavior without locks getting in your way. 
    
    All lock providers are implemented to be  "self expiring" so you won't have to worry about a stale lock having to be manually removed after a crash. 
 
* ###Memcache Wrapper
Useful for daemons that need to store runtime details in Memcached, a small Memcache wrapper is included that implements easy namespacing and auto_retry functionality. In our high-throughput memcache environment we occasionally have an issue where memcache was blocking at that specific microsecond and a key couldn't be written. To avoid this crashing the Daemon, auto-retry functionality was added. This feature will try several times to write the key -- until it reaches the timeout you specify. 
 
* ###Simple Logging
In your subclass you must implement the log_file() method to return a filename -- either a static, one-line "return './foo'" or an algorithmic log rotator. A simple log file format is used, writing the timestamp, PID, message and \n. The PHP-Daemon system will log noteworthy events and you can easily add your own entries by calling  either the ->log($message) method or, if appropriate, the ->fatal_error($message) method. An "alert" flag can be set that will email the $message to the distribution list you define in your constructor. 
    
    When run in Verbose mode, the contents of the log file are also written to STDOUT. This is also the case if the supplied filename cannot be open and written-to. 
 
* ###Simple Plugin Loader
A plugin can be created very easily by implementing the Core_PluginInterface. Currently, you must save your plugins in /Core/Plugins and they must be named using Zend Framework conventions (Core_Plugins_{ClassName}). The advantage of writing code as a Plugin, in addition to it being obviously reusable, is the ability to hook into the check_environment, setup, and destructor. The primary reason the Plugin interface was developed for v1.1.0 was to devise a way to simplify Core_Daemon and move INI file and Memcached dependence out of the core.  

* ###Simple Config Loading
A simple Plugin loader is included, and the first plugin created will help you use and validate Ini files. This functionality was moved from the daemon core into a Plugin for the 1.1.0 release to simplify daemon core. 

* ###Simple Installation Guidance 
Help sysadmins and users of your Daemon by definig installation instructions directly in your daemon. Add messages to the
$this->install_instructions array in your daemon's constructor. Then when you call the daemon with a "-i" argument, a complete list of your instructions and Core_Daemon instructions will be displayed to the daemon user. 

* ###Command Line Switches
You can run a '-H' help command when you run the Daemon. It will dump a help menu that looks like this, but can be easily overridden for your daemon:

```
Example_Daemon
   	
USAGE:
       
run.php -H | -i | [-d] [-v] [-p PID_FILE]
OPTIONS:
-i Print any daemon install instructions to the screen
-d Daemon, detach and run in the background
-v Verbose, echo any logged messages. Ignored in Daemon mode.
-H Shows this help
-p PID_FILE File to write process ID out to
```