<?php

namespace Appwrite\Storage;

use Exception;

class Storage
{
    /**
     * Devices.
     *
     * List of all available storage devices
     *
     * @var array
     */
    public static $devices = array();

    /**
     * Set Device.
     *
     * Add device by name
     *
     * @param string $name
     * @param Device $device
     *
     * @throws Exception
     */
    public static function setDevice($name, Device $device)
    {
        self::$devices[$name] = $device;
    }

    /**
     * Get Device.
     *
     * Get device by name
     *
     * @param string $name
     *
     * @return Device
     *
     * @throws Exception
     */
    public static function getDevice($name)
    {
        if (!\array_key_exists($name, self::$devices)) {
            throw new Exception('The device "'.$name.'" is not listed');
        }

        return self::$devices[$name];
    }

    /**
     * Exists.
     *
     * Checks if given storage name is registered or not
     *
     * @param string $name
     *
     * @return bool
     */
    public static function exists($name)
    {
        return (bool) \array_key_exists($name, self::$devices);
    }

    /**
     * Human readable data size format from bytes input.
     *
     * Based on: https://stackoverflow.com/a/38659168/2299554
     *
     * @param int $bytes
     * @param int $decimals
     * @param string $system
     *
     * @return string
     */
    public static function human(int $bytes, $decimals = 2, $system = 'metric')
    {
        $mod = ($system === 'binary') ? 1024 : 1000;

        $units = array(
            'binary' => array(
                'B',
                'KiB',
                'MiB',
                'GiB',
                'TiB',
                'PiB',
                'EiB',
                'ZiB',
                'YiB',
            ),
            'metric' => array(
                'B',
                'kB',
                'MB',
                'GB',
                'TB',
                'PB',
                'EB',
                'ZB',
                'YB',
            ),
        );

        $factor = (int)floor((strlen($bytes) - 1) / 3);

        return sprintf("%.{$decimals}f%s", $bytes / pow($mod, $factor), $units[$system][$factor]);
    }
}