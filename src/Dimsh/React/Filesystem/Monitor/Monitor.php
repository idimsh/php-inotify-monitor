<?php
/*
 * This file is part of Dimsh\React\Filesystem\Monitor;
 *
 * (c) Abdulrahman Dimashki <idimsh@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace Dimsh\React\Filesystem\Monitor;

use Evenement\EventEmitter;
use MKraemer\ReactInotify\Inotify;
use React\EventLoop\LoopInterface;

class Monitor extends EventEmitter
{
    use MonitorTrait;

    public const EV_CREATE = 'EV_CREATE';
    public const EV_MODIFY = 'EV_MODIFY';
    public const EV_DELETE = 'EV_DELETE';

    protected const MASK_FILE = 0
    | IN_MODIFY;

    protected const MASK_FILE_WITH_ATTRIB = 0
    | IN_MODIFY
    | IN_ATTRIB;

    /**
     * the flag
     * IN_MODIFY
     * makes no sense for directories, as they will report the modify for the first child
     * of the directory, and that MODIFY flag is set for the inner files in self::MASK_FILE
     * For directories,  the modify event is related to mtime and permissions, which is
     * included in the IN_ATTRIB flag.
     */
    protected const MASK_DIRECTORY = 0
    //    | IN_MODIFY
    | IN_MOVED_TO
    | IN_MOVED_FROM
    | IN_CREATE
    | IN_DELETE
    | IN_DELETE_SELF
    | IN_ISDIR;

    protected const MASK_DIRECTORY_WITH_MODIFY = 0
    //    | IN_MODIFY
    | IN_ATTRIB
    | IN_MOVED_TO
    | IN_MOVED_FROM
    | IN_CREATE
    | IN_DELETE
    | IN_DELETE_SELF
    | IN_ISDIR;

    protected const MASK_DIRECTORY_CREATED_ONLY = 0
    | IN_MOVED_FROM
    | IN_CREATE
    | IN_ISDIR;


    /**
     * @var \MKraemer\ReactInotify\Inotify
     */
    protected $inotify;

    /**
     * @var \Dimsh\React\Filesystem\Monitor\MonitorConfigurator
     */
    protected $configurator;

    /**
     * @var []
     */
    protected $descriptors;

    /**
     * @var \React\EventLoop\LoopInterface|null
     */
    protected $external_event_loop = null;

    /**
     * @var \React\EventLoop\LoopInterface|null
     */
    protected $internal_loop;

    /**
     * @internal
     * @var \Dimsh\React\Filesystem\Monitor\Monitor|null
     */
    protected $base_dir_monitor = null;

    /**
     * Tell if $this->setupListeners() is called.
     *
     * @internal
     * @var bool
     */
    protected $is_inotify_event_listener_attached = false;


    /**
     * MonitorMultiManual constructor.
     *
     * @param \Dimsh\React\Filesystem\Monitor\MonitorConfigurator $configurator
     * @param \React\EventLoop\LoopInterface|null                 $event_loop if passed then this event loop will be
     *                                                                        used to construct the Inotify object and
     *                                                                        should be "run" externally. When it is
     *                                                                        null an internal loop is created and
     *                                                                        method $this->run() must be called.
     */
    public function __construct(MonitorConfigurator $configurator, ?LoopInterface $event_loop = null)
    {
        $this->configurator = $configurator;
        if ($event_loop === null) {
            $this->internal_loop = \React\EventLoop\Factory::create();
        } else {
            $this->external_event_loop = $event_loop;
        }
        /**
         * Two conditions I could use below:
         * - && PHP_OS == "Linux"
         * - && file_exists('/')
         *
         * And I have chosen the second, since as the time of this writing, there is no check
         * for platform in the whole code.
         */
        if (!file_exists($configurator->getBaseDirectory()) && file_exists('/') && $configurator->getBaseDirectory() != '/' && $configurator->isAutoCreateNotFoundMonitor()) {
            // $base_directory is required to be with trailing slash
            $base_directory     = $configurator->getBaseDirectoryWithTrailingSlash();
            $level              = substr_count($base_directory, '/') - 1;
            $pattern            = $base_directory;
            $second_confiurator = MonitorConfigurator::factory()
              ->setMonitorCreatedOnly(true)
              ->setLevel($level)
              ->setFilesToMonitor([
                $pattern,
              ]);
            try {
                $second_confiurator->setBaseDirectory('/');
                $this->base_dir_monitor = new Monitor(
                  $second_confiurator,
                  $this->getEventLoop()
                );
                $this->base_dir_monitor->on(Monitor::EV_CREATE,
                  function ($path, $monitor) use ($base_directory) {
                      /**
                       * @var \Dimsh\React\Filesystem\Monitor\Monitor $monitor
                       */
                      if ($path == $base_directory) {
                          $monitor->getEventLoop()->stop();
                          $this->inotify = new Inotify($monitor->getEventLoop());
                          $this->setupListeners();
                          $this->add($this->configurator->getBaseDirectoryWithTrailingSlash());
                          $monitor->getEventLoop()->run();
                      }
                  });
            } catch (\Exception $exception) {

            }
        } else {
            $this->inotify = new Inotify($this->getEventLoop());
            $this->setupListeners();
            $this->add($this->configurator->getBaseDirectoryWithTrailingSlash());
        }
    }

    /**
     * Get the event loop used by this instance.
     *
     * It is either the internal loop created in the constructor if no external one is
     * passed to it, or the external.
     *
     * @return \React\EventLoop\LoopInterface
     */
    public function getEventLoop(): LoopInterface
    {
        return $this->external_event_loop === null ? $this->internal_loop : $this->external_event_loop;
    }

    /**
     * Fire the created event.
     *
     * If $path has a trailing slash, then a directory is created, else a file.
     * This will only be fired for files/dirs that matches the patterns defined
     * in the configurator.
     *
     * @param string $path
     */
    protected function fireCreated($path): void
    {
        $this->emit(self::EV_CREATE, [$path, $this]);
    }

    /**
     * Fire the modified event.
     *
     * If $path has a trailing slash, then a directory is modified, else a file.
     * This will only be fired for files/dirs that matches the patterns defined
     * in the configurator.
     *
     * @param string $path
     */
    protected function fireModified($path): void
    {
        $this->emit(self::EV_MODIFY, [$path, $this]);
    }

    /**
     * Fire the deleted event.
     *
     * If $path has a trailing slash, then a directory is deleted, else a file.
     * This will only be fired for files/dirs that matches the patterns defined
     * in the configurator.
     *
     * @param string $path
     */
    protected function fireDeleted($path): void
    {
        $this->emit(self::EV_DELETE, [$path, $this]);
    }

    /**
     * Setup event listeners on the inotify.
     *
     * @param bool $detach set to true to detach event listeners
     *
     * @return void
     */
    protected function setupListeners($detach = false): void
    {
        $method = $detach ? 'removeListener' : 'on';
        $this->inotify->$method(IN_MODIFY, function ($path) {
            if ($this->configurator->isPathMatchMonitor($path)) {
                $this->fireModified($path);
            }
        });

        $this->inotify->$method(IN_ATTRIB, function ($path) {
            if ($this->configurator->isPathMatchMonitor($path)) {
                $this->fireModified($path);
            }
        });

        if ($this->configurator->isFireModifiedOnDirectories()) {
            $this->inotify->$method(IN_ATTRIB | IN_ISDIR, function ($path) {
                if ($this->hasTrailingSlash($path) && $this->configurator->isPathMatchMonitor($path)) {
                    $this->fireModified($path);
                }
            });
        }

        $this->inotify->$method(IN_MOVED_FROM, function ($path) {
            $this->remove($path);
        });

        $this->inotify->$method(IN_MOVED_TO, function ($path) {
            $this->add($path);
        });

        $this->inotify->$method(IN_MOVED_FROM | IN_ISDIR, function ($path) {
            $path = $this->dirPath($path);
            $this->remove($path);
        });

        $this->inotify->$method(IN_MOVED_TO | IN_ISDIR, function ($path) {
            $path = $this->dirPath($path);
            $this->add($path);
        });

        $this->inotify->$method(IN_CREATE, function ($path) {
            $this->add($path);
        });

        $this->inotify->$method(IN_CREATE | IN_ISDIR, function ($path) {
            $path = $this->dirPath($path);
            $this->add($path);
        });

        $this->inotify->$method(IN_DELETE, function ($path) {
            $this->remove($path);
        });

        $this->inotify->$method(IN_DELETE | IN_ISDIR, function ($path) {
            $path = $this->dirPath($path);
            $this->remove($path);
        });

        $this->inotify->$method(IN_DELETE_SELF, function ($path) {
            $this->remove($path);
        });

        $this->inotify->$method(IN_DELETE_SELF | IN_ISDIR, function ($path) {
            $path = $this->dirPath($path);
            $this->remove($path);
        });

        $this->is_inotify_event_listener_attached = !$detach;
    }

    /**
     * Add a file/directory to the monitored list.
     *
     * if $path has a trailing slash then it is a directory, else a file.
     *
     * @param string $path
     *
     * @return void
     */
    protected function add($path): void
    {
        if ($this->hasTrailingSlash($path)) {
            $mask = $this->configurator->isFireModifiedOnDirectories() ? self::MASK_DIRECTORY_WITH_MODIFY : self::MASK_DIRECTORY;
            if ($this->configurator->isMonitorCreatedOnly()) {
                $mask = self::MASK_DIRECTORY_CREATED_ONLY;
            }
            if (!$this->configurator->mayDirectoryContainMonitoredItems($path)) {
                // if this directory will not contain items we want to watch, then
                // conditionally watch it if it itself matches a pattern.
                $this->conditionallyAddAndFire($path, $mask);
            } else {
                // else if it may contain other items we want to watch, then add it to
                // watch list and conditionally fire the event it its name match a pattern.
                // Then include its subitems to the watch list.
                $this->addAndConditionallyFire($path, $mask);
                $sub_items = @scandir($path);
                if ($sub_items) {
                    foreach ($sub_items as $sub_item) {
                        if ($sub_item === '.' || $sub_item === '..') {
                            continue;
                        }
                        $new_path = $path . $sub_item;
                        if (is_dir($new_path)) {
                            $this->add($new_path . '/');
                        } else {
                            $this->add($new_path);
                        }
                    }
                }
            }
        } else {
            $mask = $this->configurator->isFireModifiedOnDirectories() ? self::MASK_FILE : self::MASK_FILE_WITH_ATTRIB;
            $this->conditionallyAddAndFire($path, $mask);
        }
    }

    /**
     * If the path matches a pattern we want to monitor, then add the path to
     * the watched list and fire the created event.
     *
     * @param string $path
     * @param int    $mask
     *
     * @return void
     */
    protected function conditionallyAddAndFire($path, $mask): void
    {
        if ($this->configurator->isPathMatchMonitor($path)) {
            if (is_readable($path)) {
                if ($descriptor = $this->inotify->add($path, $mask)) {
                    $this->descriptors[$path] = $descriptor;
                    $this->fireCreated($path);
                }
            }
        }
    }

    /**
     * add the passed path to watched list and if the path match a pattern
     * configured then fire the created event
     *
     * @param string $path
     * @param int    $mask
     *
     * @return void
     */
    protected function addAndConditionallyFire($path, $mask): void
    {
        if (is_readable($path)) {
            if ($descriptor = $this->inotify->add($path, $mask)) {
                $this->descriptors[$path] = $descriptor;
                if ($this->configurator->isPathMatchMonitor($path)) {
                    $this->fireCreated($path);
                }
            }
        }
    }

    /**
     * Remove file/directory from the monitored list.
     *
     * if $path has a trailing slash then it is a directory, else a file.
     * When removing a directory, all items below it are removed from the list.
     *
     * @param string $path
     * @param bool   $skip_fire set to true to not fire the deleted event
     *
     * @return void
     */
    protected function remove($path, $skip_fire = false): void
    {
        if (isset($this->descriptors[$path])) {
            if ($this->hasTrailingSlash($path)) {
                /**
                 * If a directory is removed recursively, the inner items are not
                 * triggered the delete, so here we will remove watches for them
                 * starting from the deepest.
                 */
                $inner_paths = [];
                foreach (array_keys($this->descriptors) as $item_path) {
                    if (strpos($item_path, $path) === 0 && $item_path !== $path) {
                        $inner_paths[$item_path] = substr_count($item_path, '/');
                    }
                }
                if ($inner_paths) {
                    arsort($inner_paths, SORT_NUMERIC);
                    foreach (array_keys($inner_paths) as $inner_path) {
                        $this->remove($inner_path, $skip_fire);
                    }
                }
            }

            @$this->inotify->remove($this->descriptors[$path]);
            unset($this->descriptors[$path]);

            if (!$skip_fire && $this->configurator->isPathMatchMonitor($path)) {
                $this->fireDeleted($path);
            }
        }
    }

    /**
     * Run the event loop, this will block.
     *
     * If the event loop is passed externally to the constructor, then a user warning is triggered.
     */
    public function run()
    {
        if ($this->external_event_loop !== null) {
            trigger_error(
              'calling run on Monitor external loop inside the monitor, this is mostly in-correct as the external loop must be run from the caller.',
              E_USER_WARNING
            );
        }
        return $this->getEventLoop()->run();
    }

    /**
     * Stop the monitor
     * @return void
     */
    public function stop(): void
    {
        if ($this->is_inotify_event_listener_attached) {
            $this->setupListeners(true);
        }
        $this->remove($this->configurator->getBaseDirectoryWithTrailingSlash(), true);
        if ($this->base_dir_monitor instanceof Monitor) {
            $this->base_dir_monitor->stop();
        }
    }
}
