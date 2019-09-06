<?php

namespace Storage;

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
        if (array_key_exists($name, self::$devices)) {
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
        if (!array_key_exists($name, self::$devices)) {
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
        return (bool) array_key_exists($name, self::$devices);
    }
}
