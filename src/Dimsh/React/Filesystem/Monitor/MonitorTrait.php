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

/**
 * Trait MonitorTrait
 *
 * Contains common methods used by MonitorConfigurator and Monitor
 *
 * @package Dimsh\React\Filesystem\Monitor
 */
trait MonitorTrait
{

    /**
     * Return path with trailing slash.
     *
     * @param string $path
     *
     * @return string
     */
    public function dirPath($path)
    {
        return rtrim($path, '/') . '/';
    }

    /**
     * Directories have trailing slash/
     *
     * @param string $path
     *
     * @return bool
     */
    public function hasTrailingSlash($path)
    {
        return substr($path, -1) === '/';
    }

    /**
     * Fix a Linux path (normalize).
     * - replace consecutive '/' with one
     * - replace '/./' with '/'
     * - replace '/../' at the begining with '/'
     * - remove backward pathes '??/../'
     *
     * @param string $path
     *
     * @return string
     */
    public function fixPathSlashes($path)
    {
        for ($i = 0; $i < 50; $i++) {
            $original_path = $path;
            $path          = preg_replace('@//+@', '/', $path);
            $path          = str_replace('/./', '/', $path);
            $path          = preg_replace('@^/\.\.(/|$)@', '/', $path, 1);
            $path          = preg_replace('@[^/]+/\.\.(/|$)@', '/', $path, 1);

            if ($path == $original_path) {
                break;
            }
        }

        return $path;
    }
}
