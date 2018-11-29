[![Build Status](https://travis-ci.org/idimsh/php-inotify-monitor.svg?branch=master)](https://travis-ci.org/idimsh/php-inotify-monitor)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/idimsh/php-inotify-monitor/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/idimsh/php-inotify-monitor/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/idimsh/php-inotify-monitor/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/idimsh/php-inotify-monitor/?branch=master)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)  

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

or in `composer.json`
```json
{
  "require": {
    "idimsh/php-inotify-monitor": "dev-master"
  }
}
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
* `Monitor`: Define the event handlers and perform actions when events are caught.  
The constructor accepts a `MonitorConfigurator` object as dependency.   
Inherits `\Evenement\EventEmitter`.

#### API Methods of `MonitorConfigurator`:
1. `setBaseDirectory()`: Defines the directory the Monitor will watch changes in, it 
must be in absolute path and if not: the current working directory is used to 
construct an absolute path, so an empty Base Directory is valid to be passed.   
The general rule is: DO NOT set it to the root `'/'` directory, and do not operate on a 
directory where lot of files are being modified like: `/var/log/`.  
If the directory specified represents a file, an `\Exception` is thrown.

2. `setLevel()`: Specify the level inside Base Directory to recurse to, default to 1 
which is directly inside Base Dir.  
If Base Dir is `/var/www/` then:  
&nbsp;&nbsp;&nbsp;&nbsp;`/var/www/file1.html` is at level 1  
&nbsp;&nbsp;&nbsp;&nbsp;`/var/www/html/file2.html` is at level 2  
Setting level to 0 (Zero) will recurse to all sub directories.

3. `setFilesToMonitor([])`: Accepts an array of shell patterns which represent the 
files and/or directories the Monitor will watch changes for and trigger the events.  
When a file (or directory) that matches a pattern defined here has been: created, 
modified, or deleted the defined handler function for that event is called ang get
passed the absolute path to the file or directory along with the Monitor object 
instance.   
You can target directories by specifying shell patterns that ends with a slash `'/'`, 
file patterns does not end with slash.<br>  
Patterns which do not contain a slash `'/'` in the middle are recursive to the level 
defined by `setLevel()`, so an entry like: `'*.yaml'` will match any file with 
extension `yaml` up to the level defined.   
**But** patterns which include a slash in the middle or at the beginning are considered 
in absolute form and are not recursive, so an entry like: `'*/*.yaml'` (is exactly 
equivalent to the entry `'/*/*.yaml'`) will match any file with extension `yaml`
which are under base directory at level 2  and it will not match them at any
other level (unless other entry in the list instructs so).<br>  
**Patterns must not include the Base Directory** patterns are checked for 
files/directories inside the Base Dir after removing Base Dir from 
their absolute path.  
  
4. `setFireModifiedOnDirectories()`: Default to false. Set to true to fire the 
modified event on directories, this is fired when a directory attributes 
(mtime, permissions) are changed. The modified event will be fired only if the
directory matches one of the patterns specified by `setFilesToMonitor([])`
_(again patterns which matches directories are those ending with slash `'/'`)_.  
In order to monitor the base directory itself for changes when this flag is on,
pattern entry would be the empty string `''`, it has the special meaning of: match
the Base Dir if `fire_modified_on_directories` is on.  
  
5. `setMonitorCreatedOnly()`: Default to false. Set to true to only watch for 
the "CREATED" event, used by Monitor itself for quick waiting for non 
existent Base Dir.

6. `setAutoCreateNotFoundMonitor()`: Default to false. Set to true and Monitor 
instance will create another inner Monitor automatically for non existent Base Dir. 
Usually when the Monitor is run and base directory does not exist on the 
file system, the run call will return immediately and nothing will be monitored.
With this flag set to `true` an internal Monitor object instance will be created 
which is optimized to monitor the root directory `'/'` (as Base Dir) for the 
creation of the Base Dir of the parent Monitor instance.   

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
                       # a slash so not recursive and is at level 2
                       # In fact with this config level is set internally to 2, since there is no 
                       # pattern which will match at levels 3 to 5.
  ]));
```
  
  
#### API Methods of `Monitor`:
The constructor of `Monitor` accepts a `MonitorConfigurator` instance and optionally an instance of an external 
React PHP LoopInterface (or React PHP EventLoop). If no external EventLoop is passed, an internal one is created.  
The loop must be _ran_ and the `run()` method of Monitor will execute the `run()` call on the EventLoop (The 
external or the internal).  
Use an external EventLoop if for example two Monitors need to watch two different Base Directories (with different 
patterns for each), then create an external Loop, pass it to both instances of `Monitor` and fire the `run()` 
externally after initializing both.  

The Monitor extends the `EventEmitter` class and the instance of it can be setup to
listen to events using `on()` method, recognized events are:
* `Monitor::EV_CREATE`
* `Monitor::EV_MODIFY`
* `Monitor::EV_DELETE`  

The callback function to each event is passed the file/directory path which the event occurs on and the instance of 
the `Monitor` which fires the event.  
**Directories are passed to the callback with a trailing slash.**  

Calling `run()` on EventLoop will block, so the worker process which utilize this class has to handle it.

1. `run()`: will run the event loop (whether the external one passed to the 
constructor or the internally created one). This call will block.  
If `run()` is called and an external event loop is passed to the constructor 
a warning of type `user` is generated to inform the caller that external event
loops are meant to be called not by the monitor itself. 

2. `stop()`: stop the Monitor by removing all watches registered. 

3. `stopAll()`: stop the Monitor by removing all watches registered and call `stop()` 
on the event loop, causing it to stop.

4. `stopQuick()`: stop the Monitor by a quick call to inotify close. Not 
recommended unless in special cases (like inside POSIX signal handler).

5. `stopQuickAll()`: like `stopQuick()` and will call stop on the event loop also.   
  
_example_  
```PHP
/**
 * This example will monitor the file upload directory of a web application
 * for PHP files and automatically delete any PHP file created or uploaded.
 */
 
require_once 'vendor/autoload.php';

use Dimsh\React\Filesystem\Monitor\Monitor;
use Dimsh\React\Filesystem\Monitor\MonitorConfigurator;

$base_dir = '/tmp/uploads';

$monitor_config = MonitorConfigurator::factory()
  ->setLevel(2)
  ->setAutoCreateNotFoundMonitor(true)// wait for $base_dir to be created if not exists.
  ->setFireModifiedOnDirectories(true)
  ->setFilesToMonitor([
    '*.php',                  // monitor any php file in the uploads directory.
    '',                       // since ->setFireModifiedOnDirectories(true) is set,
                              // this entry means that we want to be notified if
                              // the $base_dir has been modified also (permissions).
    '*/*.swf',                // monitor swf files at level 2 only
    '/stop-if-created/',      // monitor this exact directory at level 1, and in our
                              // event handler we will stop the monitor if this
                              // directory is created.
  ]);

try {
    $monitor_config->setBaseDirectory($base_dir);
} catch (\Exception $e) {
    die("exception thrown: [{$e->getMessage()}]\n");
}
$monitor = new Monitor($monitor_config);
$monitor
  ->on(Monitor::EV_CREATE, function ($path, $monitor) use ($base_dir) {
      /** @var Monitor $monitor */
      if ($path === "$base_dir/stop-if-created/") {
          echo "stopping ... \n";
          $monitor->stop();
      }
      if ($monitor->hasTrailingSlash($path)) {
          // this will just print once for:
          // "directory created:   $base_dir/stop-if-created/"
          // since we are not monitoring any directory with our set patterns
          // except one, the stop will occur after all event handlers are fired.
          echo "directory created:   $path\n";
      } else {
          echo "file created:   $path\n";
          if (strtolower(substr($path, -4)) === '.php') {
              echo "php files are not allowed to be created in upload directory\n";
              @unlink($path);
          }
      }
  })
  ->on(Monitor::EV_MODIFY, function ($path, $monitor) use ($base_dir) {
      if ($base_dir === $path) {
          echo "base directory modified:  $path\n";
      } else {
          echo "modified:  $path\n";
      }
  })
  ->on(Monitor::EV_DELETE, function ($path, $monitor) {
      // this will be printed for PHP files which we are deleting on our created handler also.
      echo "deleted:   $path\n";
  });

$monitor->run();
```

#### Notes:
In case of PHP warnings like this are encountered:  
`PHP Warning:  inotify_add_watch(): The user limit on the total number of inotify
watches was reached or the kernel failed to allocate a needed resource in
1vendor/mkraemer/react-inotify/src/MKraemer/ReactInotify/Inotify.php`  
  
The following command can solve the issue:
```bash
$ echo 999999 | sudo tee -a /proc/sys/fs/inotify/max_user_watches && \
echo 999999 | sudo tee -a /proc/sys/fs/inotify/max_queued_events && \
echo 999999 | sudo tee -a /proc/sys/fs/inotify/max_user_instances && \
sudo sysctl -p
```

But this warning may indicate that you are setting up the watcher with too many resources which can mean bad configuration.

## License
MIT
