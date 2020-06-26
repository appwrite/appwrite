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
     * Add Device.
     *
     * Add device by name
     *
     * @param string $name
     * @param Device $device
     *
     * @throws Exception
     */
    public static function addDevice($name, Device $device)
    {
        if (\array_key_exists($name, self::$devices)) {
            throw new Exception('The device "'.$name.'" is already listed');
        }

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
     * As published on https://gist.github.com/liunian/9338301 (first comment)
     *
     * @param int $bytes
     * @param int $decimals
     *
     * @return string
     */
    public static function human($bytes, $decimals = 2)
    {
        $units = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
        $step = 1024;
        $i = 0;

        while (($bytes / $step) > 0.9) {
            $bytes = $bytes / $step;
            ++$i;
        }

        return \round($bytes, $decimals).$units[$i];
    }
}
