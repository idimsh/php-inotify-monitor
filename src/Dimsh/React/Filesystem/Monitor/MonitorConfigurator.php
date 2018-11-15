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

class MonitorConfigurator
{
    use MonitorTrait;

    /**
     * @var string
     */
    protected $base_directory = '';

    /**
     * @var int
     */
    protected $level = 1;

    /**
     * The nesting level to work on after analyzing the patterns in $files_to_monitor
     *
     * @internal
     * @var int
     */
    protected $effective_level = 0;

    /**
     * @var bool
     */
    protected $monitor_created_only = false;

    /**
     * @var string[]
     */
    protected $files_to_monitor = [];

    /**
     * @var bool
     */
    protected $auto_create_not_found_monitor = false;

    /**
     * Array where keys are the patterns set by $this->setFilesToMonitor() after applying
     * fixes to them by $this->fixPathsSlashes() (remove duplicated slashes and so)
     * The value for each is an array with the following keys:
     * 'end_with_slash'    : Is the pattern ends with a slash? then it is considered
     *                       a pattern for a directory which needs to be monitored
     * 'is_not_recursive'  : Is this pattern represents a specific level matcher (not recursive)
     *                       this happens when the pattern has at least one slash character in
     *                       the middle and in this case it will not be considered as a recursive
     *                       pattern.
     * 'path_level'        : The path level of the pattern (count of slashes excluding the leading
     *                       and the trailing, this should be 0 for recursive patterns and greater
     *                       than 0 for non recursive.
     * @internal
     * @var [][]
     */
    protected $analyzed_files_to_monitor = [];

    /**
     * Tell if the patterns passed to $this->setFilesToMonitor() has a pattern
     * with empty string which indicates the caller wants to monitor the
     * base directory for changes.
     *
     * @internal
     * @var bool
     */
    protected $files_to_monitor_has_empty_string = false;

    /**
     * This is for speed optimization, it indicates what all the patterns in
     * $this->analyzed_files_to_monitor has the key: 'is_not_recursive' as true.
     *
     * @internal
     * @var bool
     */
    protected $is_all_files_to_monitor_not_recursive = false;

    /**
     * an array of dirname() calls to each pattern in the keys of:
     * $this->analyzed_files_to_monitor -- recursively until we get
     * the empty string.
     *
     * This is used for performance optimization.
     *
     * All the entries are without leading slash.
     *
     * This array does not contain the empty string, and do not have duplicates.
     *
     * @internal
     * @var array
     */
    protected $dirnames_from_files_to_monitor = [];

    /**
     * The base directory string applied to preg_quote($base_dir, '@')
     *
     * @internal
     * @var string
     */
    protected $preg_quoted_base_directory = '';

    /**
     * Whether to fire the modified events on directories, default to false.
     *
     * @var bool
     */
    protected $fire_modified_on_directories = false;


    /**
     * @return MonitorConfigurator
     */
    public static function factory()
    {
        return new static();
    }

    /**
     * Tell if path (directory or file) is to be monitored by this config.
     *
     * Directories ends with slash.
     *
     * @param string $path
     *
     * @return bool
     */
    public function isPathMatchMonitor(string $path)
    {
        if (strpos($path, $this->base_directory) !== 0) {
            return false;
        }
        $trailing_slash = '';
        if ($this->hasTrailingSlash($path) && $path !== '/') {
            $trailing_slash = '/';
            $path           = rtrim($path, '/');
        }
        $remaining_path = preg_replace('@^' . $this->preg_quoted_base_directory . '@', '', $path);
        // Here $remaining_path is starting with slash (or be empty string)
        if ($remaining_path === '') {
            // this happens if $path = $this->getBaseDirecotry()
            // In this case the only pattern in $this->files_to_monitor array to match
            // is the empty string, which will be checked.
            return $this->files_to_monitor_has_empty_string;
        }
        /*
         * Here $remaining_path has a leading slash unless Base Directory is
         * the root '/' dir, And we have to fix the path level in that case
         */
        $path_level = substr_count($remaining_path, '/');
        if ($this->base_directory === '/') {
            $path_level++;
        }
        if ($this->effective_level > 0 && $path_level > $this->effective_level) {
            return false;
        }
        /*
         * Here $remaining_path has a leading slash (unless Base Directory is
         * the root '/' dir), which will cause troubles with patterns
         * because patterns are meant to be always relative to the base directory.
         *
         * Here we will remove the leading slash.
         */
        $remaining_path = ltrim($remaining_path, '/');
        return $this->isStringMatch($remaining_path . $trailing_slash);
    }

    /**
     * Match string against all patterns saved in $this->getFilesToMonitor()
     * array of patterns (or names)
     *
     * @param string $relative_path
     *
     * @return bool
     */
    protected function isStringMatch($relative_path)
    {
        /**
         * $relative_path will never be '/' if this method is called from $this->isPathMatchMonitor()
         */
        $has_trailing_slash               = $this->hasTrailingSlash($relative_path) && $relative_path !== '/';
        $relative_path_with_leading_slash = substr($relative_path, 0, 1) === '/' ?
          $relative_path : '/' . $relative_path;
        $basename_relative_path           = basename($relative_path) . ($has_trailing_slash ? '/' : '');
        foreach ($this->analyzed_files_to_monitor as $pattern => $properties) {
            if ($has_trailing_slash && !$properties['end_with_slash']) {
                /**
                 * if the passed path is a directory, then it matches only patterns which end
                 * with slash, not others.
                 */
                continue;
            }
            if ($properties['is_not_recursive']) {
                /**
                 * $pattern does not have a leading slash, and since it is not
                 * recursive, here we will ensure both the pattern and the $relative_path
                 * have leading slashes to better match the path level accurately.
                 */
                if (fnmatch('/' . $pattern, $relative_path_with_leading_slash, FNM_PATHNAME | FNM_PERIOD)) {
                    return true;
                }
            } else {
                if (fnmatch($pattern, $basename_relative_path)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Tell if the passed directory may contain items that matches the patterns,
     * it may contain them if its nest level is less than the configured level
     *
     * @param string $directory
     *
     * @return bool
     */
    public function mayDirectoryContainMonitoredItems($directory)
    {
        if (strpos($directory, $this->base_directory) !== 0) {
            return false;
        }
        if ($this->effective_level == 0 || $directory === $this->base_directory) {
            return true;
        }
        if ($directory !== '/') {
            $directory = rtrim($directory, '/');
        }
        $remaining_path = preg_replace('@^' . $this->preg_quoted_base_directory . '@', '', $directory);
        /*
         * Here $remaining_path has a leading slash unless Base Directory is
         * the root '/' dir, and we have to fix the path level in that case.
         */
        $path_level = substr_count($remaining_path, '/');
        if ($this->base_directory === '/') {
            $path_level++;
        }
        if ($this->effective_level > 0 && $path_level >= $this->effective_level) {
            return false;
        }
        /*
         * Here I want to search for the false more, for performance reasons
         * it is better to report false early for directories which will not
         * have any matches.
         *
         * will add the leading slash to remaining path
         */
        if (substr($remaining_path, 0, 1) !== '/') {
            $remaining_path = '/' . $remaining_path;
        }
        if ($this->is_all_files_to_monitor_not_recursive) {
            $matched_dirname = false;
            /**
             * $dirname_pattern is without a leading slash, I will add it since
             * $remaining_path has it and I want to restrict the matching more.
             */
            foreach ($this->dirnames_from_files_to_monitor as $dirname_pattern) {
                if (fnmatch('/' . $dirname_pattern, $remaining_path, FNM_PATHNAME | FNM_PERIOD)) {
                    $matched_dirname = true;
                    break;
                }
            }
            if (!$matched_dirname) {
                // no dirname pattern will match this path, we return false!
                return false;
            }
        }
        return true;
    }

    /**
     * Get an array of dirnames called on $path recursively.
     *
     * @param string $path
     *
     * @return array
     */
    protected function getDirnamesWithoutEmpty(string $path): array
    {
        $dirnames = [];
        // dirname('hi') == dirname('hi/') == '.'
        while (($dirname = dirname($path)) && $dirname != '.' && $dirname != $path) {
            $dirnames[] = $dirname;
            $path       = $dirname;
        }
        return $dirnames;
    }

    ////////////////////////////////////
    // Getters and Setters come next  //
    ////////////////////////////////////

    /**
     * Base dir is always without trailing slash unless it is the root '/' directory.
     *
     * @return string
     */
    public function getBaseDirectory(): string
    {
        return $this->base_directory;
    }

    /**
     * Return Base dir with trailing slash.
     *
     * @return string
     */
    public function getBaseDirectoryWithTrailingSlash(): string
    {
        return $this->base_directory == '/' ? $this->base_directory : $this->base_directory . '/';
    }

    /**
     * Set Base dir, trailing slash is removed.
     *
     * The directory may exist or not, if it is a relative path, the current directory
     * is used to construct an absolute path.
     *
     * If the directory exists and is a file, an Exception is thrown.
     *
     * @param string $base_directory
     *
     * @return MonitorConfigurator
     *
     * @throws \Exception
     */
    public function setBaseDirectory(string $base_directory): MonitorConfigurator
    {
        if (substr($base_directory, 0, 1) !== '/') {
            $base_directory = getcwd() . '/' . $base_directory;
        }
        if (file_exists($base_directory) && !is_dir($base_directory)) {
            throw new \Exception("base directory [$base_directory] passed to MonitorConfigurator represents an existing non-directory, it must be an existing directory or not existing at all.");
        }
        $this->base_directory = $this->fixPathSlashes($base_directory);
        if ($this->base_directory !== '/') {
            $this->base_directory = rtrim($this->base_directory, '/');
        }
        $this->preg_quoted_base_directory = preg_quote($this->base_directory, '@');
        return $this;
    }

    /**
     * @return int
     */
    public function getLevel(): int
    {
        return $this->level;
    }

    /**
     * Report the effective level we operate at according to the analysis of the patterns.
     *
     * @return int
     */
    public function getEffectiveLevel(): int
    {
        return $this->effective_level;
    }

    /**
     * Set the deep level to look for files, 0 means all recursive, 1 means
     * only directly inside Base-Dir, 2 means directly inside Base-Dir and
     * directly inside any direct child-directories in it, and so on.
     *
     * @param int $level
     *
     * @return MonitorConfigurator
     */
    public function setLevel(int $level): MonitorConfigurator
    {
        $this->level = $level;
        if (!empty($this->files_to_monitor)) {
            /**
             * This is needed because we re-analyze the patterns depending on the new level
             */
            $this->setFilesToMonitor($this->files_to_monitor);
        }
        return $this;
    }

    /**
     * @return string[]
     */
    public function getFilesToMonitor(): array
    {
        return $this->files_to_monitor;
    }

    /**
     * Set an array of Shell-Pattern for files or directories to monitor,
     * directory pattern must end with trailing slash.
     *
     * These patterns are meant to be relative to the base directory set by
     * $this->setBaseDirectory(), but leading slash has special meaning: it
     * will cause the pattern not to be recursive.
     * Leading slash in any pattern item will be removed internally and will
     * set a flag that this pattern will has a directory level set.
     *
     * To match directories inside base directory, you can pass the path or
     * path pattern as an item in the array, like:
     * [
     *   'dir1/*.xml',
     *   '* /dir2/*.py', # space used because comment ending matched
     * ]
     * The previous two patterns are not recursive because they have a slash
     * inside the pattern and they are equal to the following:
     * [
     *   '/dir1/*.xml',
     *   '/* /dir2/*.py', # space used because comment ending matched
     * ]
     *
     *
     * Example:
     *  ['*.yaml', 'log/']
     * This will monitor any file with extension 'yaml' inside Base-Dir and
     * recursively up to level set by setLevel() for changes (create, modify,
     * delete) and will do the same for any directory who's basename is 'log'
     *
     * Passing something like:
     *
     * ['*'.'/dir2/*.py'] will not search recursively, but will be limited to level 3
     * inside base directory no more and no less, and it can match if the level set
     * is greater or equal to 3.
     *
     * Level is checked for all patterns those which has '/' (slash) or not.
     *
     *
     * @param string[] $files_to_monitor
     *
     * @return MonitorConfigurator
     */
    public function setFilesToMonitor(array $files_to_monitor): MonitorConfigurator
    {
        $this->files_to_monitor                      = $files_to_monitor;
        $this->analyzed_files_to_monitor             = [];
        $this->dirnames_from_files_to_monitor        = [];
        $this->files_to_monitor_has_empty_string     = false;
        $this->is_all_files_to_monitor_not_recursive = true;
        $path_levels_array                           = [];

        foreach ($this->files_to_monitor as $pattern) {
            $pattern           = $this->fixPathSlashes($pattern);
            $has_leading_slash = false;
            if ($pattern && substr($pattern, 0, 1) === '/') {
                // if $pattern = '/', then it will become the empty string which will match
                // the base directory only
                $pattern           = ltrim($pattern, '/');
                $has_leading_slash = true;
            }
            if ($pattern === '') {
                $this->files_to_monitor_has_empty_string = true;
            }

            if (!isset($this->analyzed_files_to_monitor[$pattern])) {
                $this->analyzed_files_to_monitor[$pattern] = [
                  'end_with_slash' => substr($pattern, -1, 1) === '/',
                ];

                $count_slashes = substr_count($pattern, '/');

                $this->analyzed_files_to_monitor[$pattern]['is_not_recursive'] = (
                  $count_slashes > 1 && $this->analyzed_files_to_monitor[$pattern]['end_with_slash']
                  ||
                  $count_slashes > 0 && !$this->analyzed_files_to_monitor[$pattern]['end_with_slash']
                  ||
                  $pattern === ''
                  ||
                  $has_leading_slash
                );

                $path_level = $count_slashes;
                if ($this->analyzed_files_to_monitor[$pattern]['end_with_slash']) {
                    $path_level--;
                }
                if ($path_level > 0) {
                    /*
                     * This happens if a pattern is like:
                     * 'dir/*.ext'
                     * 'dir/another_dir/'  (trailing slash for this is not counted as we decreased it already)
                     * '/dir1/dir2/f*.xml' (leading slash for this is not counted as we removed it already)
                     *
                     * In these cases and because there is a slash in the pattern, the path level must
                     * be increased by 1 because in the examples above:
                     * -the first line is targeting files at level 2
                     * -the second targeting a directory at level 2
                     * - the third targets files at level 3
                     */
                    $path_level++;
                }
                if ($this->level > 0 && $path_level > $this->level) {
                    /*
                     * We discard the whole pattern if the level of the pattern
                     * is not going to match.
                     * this is for speed optimization.
                     */
                    unset($this->analyzed_files_to_monitor[$pattern]);
                    continue;
                }
                $this->analyzed_files_to_monitor[$pattern]['path_level'] = $path_level;
                $path_levels_array[]                                     = $path_level;

                $this->is_all_files_to_monitor_not_recursive &= $this->analyzed_files_to_monitor[$pattern]['is_not_recursive'];
                $this->dirnames_from_files_to_monitor        = array_unique(
                    array_merge(
                        $this->dirnames_from_files_to_monitor,
                        $this->getDirnamesWithoutEmpty($pattern)
                    )
                );
            }
        }

        $this->effective_level = $this->level;
        if (!empty($path_levels_array)) {
            $this->effective_level = max($path_levels_array);
            if (0 === min($path_levels_array)) {
                $this->effective_level = 0;
            }
            if ($this->level !== 0) {
                if ($this->effective_level > $this->level) {
                    // this condition will never happen, just in case
                    $this->effective_level = $this->level;
                }
                if ($this->effective_level < $this->level && $this->effective_level === 0) {
                    $this->effective_level = $this->level;
                }
            }
            /*
             * Else: if $this->level is 0 (recursive)
             * Then $this->effective_level might be 0 which is what is set, or greater than 0 which is
             * good for performance
             */
        }
        return $this;
    }

    /**
     * @return bool
     */
    public function isFireModifiedOnDirectories(): bool
    {
        return $this->fire_modified_on_directories;
    }

    /**
     * Set to true to also fire the modified event on directories.
     *
     * @param bool $fire_modified_on_directories
     *
     * @return MonitorConfigurator
     */
    public function setFireModifiedOnDirectories(bool $fire_modified_on_directories): MonitorConfigurator
    {
        $this->fire_modified_on_directories = $fire_modified_on_directories;
        return $this;
    }

    /**
     * Is this monitor configuration to monitor the CREATED event only.
     *
     * @return bool
     */
    public function isMonitorCreatedOnly(): bool
    {
        return $this->monitor_created_only;
    }

    /**
     * Set a config to monitor the created event only, no modified and no delete.
     *
     * @param bool $monitor_created_only
     *
     * @return MonitorConfigurator
     */
    public function setMonitorCreatedOnly(bool $monitor_created_only): MonitorConfigurator
    {
        $this->monitor_created_only = $monitor_created_only;
        return $this;
    }

    /**
     * This instructs the monitor to create a child monitor when the base directory is not found.
     *
     * @return bool
     */
    public function isAutoCreateNotFoundMonitor(): bool
    {
        return $this->auto_create_not_found_monitor;
    }

    /**
     * Set to true to create another Monitor object which waits for the Base Directory to be created in case the
     * Base Directory does not exists.
     *
     * @see \Dimsh\React\Filesystem\Monitor\Monitor::__construct()
     *
     * @param bool $auto_create_not_found_monitor
     *
     * @return MonitorConfigurator
     */
    public function setAutoCreateNotFoundMonitor(bool $auto_create_not_found_monitor): MonitorConfigurator
    {
        $this->auto_create_not_found_monitor = $auto_create_not_found_monitor;
        return $this;
    }
}
