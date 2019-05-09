<?php

namespace Audit;

use Exception;

abstract class Adapter
{
    protected $namespace = '';

    /**
     * Set Namespace
     *
     * Set namespace to divide different scope of data sets
     *
     * @param $namespace
     * @throws Exception
     * @return bool
     */
    public function setNamespace($namespace)
    {
        if(empty($namespace)) {
            throw new Exception('Missing namespace');
        }

        $this->namespace = $namespace;

        return true;
    }

    /**
     * Get Namespace
     *
     * Get namespace of current set scope
     *
     * @throws Exception
     * @return string
     */
    public function getNamespace()
    {
        if(empty($this->namespace)) {
            throw new Exception('Missing namespace');
        }

        return $this->namespace;
    }

    /**
     * Log
     *
     * Add specific event log
     *
     * @param int $userId
     * @param int $userType
     * @param string $event
     * @param string $resource
     * @param string $userAgent
     * @param string $ip
     * @param string $location
     * @param array $data
     * @return
     */
    abstract public function log($userId, $userType, $event, $resource, $userAgent, $ip, $location, $data);

    /**
     * Get All Logs By User
     *
     * Get all user logs
     *
     * @param int $userId
     * @param int $userType
     * @return mixed
     */
    abstract public function getLogsByUser($userId, $userType);

    /**
     * Get All Logs By User and Actions
     *
     * Get all user logs by given action names
     *
     * @param int $userId
     * @param int $userType
     * @param array $actions
     * @return mixed
     */
    abstract public function getLogsByUserAndActions($userId, $userType, array $actions);
}
