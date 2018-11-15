# React PHP Inotify Monitor / Watcher
## Wrapper around PHP Inotify Extension and React PHP
A library monitor file system changes utilizing [React PHP Event Loop](https://github.com/reactphp/event-loop) and [React Inotify](https://github.com/mkraemer/react-inotify/)

It was created to monitor 3 basic events: Created, Modified, and Deleted. And to reduce the many events need to be used with [React Inotify](https://github.com/mkraemer/react-inotify/) to just simple 3.

## Requirements
* PHP >= 7.1
* inotify php extension
* Linux like system where inotify extension is available

## Features
* Monitor files and directories 
* Configured patterns to monitor using shell patterns
* Configured nesting level for monitoring, supported at global level and at pattern level
* Base Directory needs not to exist prior to running the loop, it will be automatically (and optionally) waited to be created.
* Optimized for speed
  
## Installation

```bash
$ composer require idimsh/php-inotify-monitor
```
  
#### Basic usage:
```PHP
require_once 'vendor/autoload.php';

use Dimsh\React\Filesystem\Monitor\Monitor;
use Dimsh\React\Filesystem\Monitor\MonitorConfigurator;

$monitor = new Monitor(MonitorConfigurator::factory()
  ->setBaseDirectory('/tmp')
  ->setLevel(2)
  ->setFilesToMonitor([
    '*.yaml',
  ]));
$monitor
  ->on(Monitor::EV_CREATE, function ($path, $monitor) {
      echo "created:   $path\n";
  })
  ->on(Monitor::EV_MODIFY, function ($path, $monitor) {
      echo "modified:  $path\n";
  })
  ->on(Monitor::EV_DELETE, function ($path, $monitor) {
      echo "deleted:   $path\n";
  })
  ->run();
```
That code will monitor `*.yaml` files up to two levels inside `/tmp/` so the following patterns match: `/tmp/*.yaml` and `/tmp/*/*.yaml` but not `/tmp/*/*/*.yaml`

#### Classes
Two classes defined:
* `MonitorConfigurator`: used to configure the monitor
* `Monitor`: a sub class of `\Evenement\EventEmitter` which accepts a `MonitorConfigurator` object as dependency 
and events handlers are defined with it.

##### API Methods of `MonitorConfigurator`:
1. `setBaseDirectory()`: Should be called first to define the directory a Monitor will watch changes in, it 
must be in absolute path and if not the current working directory is used to construct an absolute path, 
so an empty Base Directory is valid.   
The general rule is: DO NOT set it to the root `'/'` directory, and do not operate on a directory where lot 
of files are being modified like: `/var/log/`.  
If the directory specified represents a file, an `\Exception` is thrown.

2. `setLevel()`: Specify the level inside Base Directory to recurse to, default to 1 which is directly inside Base Dir.
If Base Dir is `/var/www/` then:  
&nbsp;&nbsp;&nbsp;&nbsp;`/var/www/file1.html` is at level 1  
&nbsp;&nbsp;&nbsp;&nbsp;`/var/www/html/file2.html` is at level 2  
Setting level to 0 (Zero) will recurse to all levels.

3. `setFilesToMonitor([])`: Accepts an array of shell patterns which represent the files and/or directories the Monitor 
will watch changes for and trigger the events.  
You can target directories by specifying shell patterns that ends with a slash `'/'`, file patterns does not end with slash.  
Patterns which do not contain a slash `'/'` in the middle are recursive to the level defined by `setLevel()`, patterns 
which include a slash in the middle or at the beginning are considered in absolute form and are not recursive.  
**Patterns must not include the Base Directory** patterns are checked for files/directories inside the Base Dir after
removing Base Dir from their absolute path.  
  
4. `setFireModifiedOnDirectories()`: Default to false. Set to true to fire the modified event on directories, this is fired when a directory attributes (mtime, permissions) are changed.  
5. `setMonitorCreatedOnly()`: Default to false. Set to true to only watch for the "CREATED" event, used by Monitor itself for quick waiting for non existent Base Dir.
6. `setAutoCreateNotFoundMonitor()`: Default to false. Set to true and Monitor will create another inner Monitor automatically for non existent Base Dir.


_examples_  
```PHP
MonitorConfigurator::factory()
  ->setBaseDirectory('/tmp')
  ->setLevel(2)
  ->setFilesToMonitor([
    '*.yaml',    # Will monitor and matches files: '/tmp/*.yaml', '/tmp/*/*.yaml' only
    '/*.xml',    # Will monitor and matches files: '/tmp/*.xml' only, '/tmp/*/*.xml' are not 
                 # monitored because this pattern starts with a slash and is not recursive.
    'config*/',  # Will monitor and matches directories: '/tmp/config-yaml/', '/tmp/config*/', '/tmp/*/config*/'
                 # (This only makes scense if setFireModifiedOnDirectories() is set to true)
                
  ]));
```

```PHP
MonitorConfigurator::factory()
  ->setBaseDirectory('/etc')
  ->setLevel(5)
  ->setFilesToMonitor([
    'nginx/*.conf',    # Will monitor and matches files: '/etc/nginx/*.conf', this pattern constains 
                       # a slash so it is not recursive and it is at level 2
                       # In fact the this config will use internally is 2, since there is no pattern
                       # which will match at the specified level 5 or below.
  ]));
```
  
  
##### API Methods of `Monitor`:
The constructor of `Monitor` accepts a `MonitorConfigurator` instance and optionally an instance of an external 
React PHP LoopInterface (or React PHP EventLoop). If no external EventLoop is passed, an internal one is created.  
The loop must be _ran_ and the `run()` method of Monitor will execute the `run()` call on the EventLoop (The 
external or the internal).  
Use an external EventLoop if for example two Monitors need to watch two different Base Directories (with different 
patterns for each), then create an external Loop, pass it to both instances of `Monitor` and fire the `run()` 
externally after initializing both.  

Listen to events using `on()` method, recognized events are:
* `Monitor::EV_CREATE`
* `Monitor::EV_MODIFY`
* `Monitor::EV_DELETE`  

The callback function to each event is passed the file/directory path which the event occurs on and the instance of 
the `Monitor` which fires the event.  
**Directories are passed to the callback with a trailing slash.**  

Calling `run()` on EventLoop will block, so the worker process which utilize this class has to handle it.


  
