<?php

namespace Appwrite\Services;

use Exception;
use Appwrite\Client;
use Appwrite\Service;

class Health extends Service
{
    /**
     * Check API HTTP Health
     *
     * Check the Appwrite HTTP server is up and responsive.
     *
     * @throws Exception
     * @return array
     */
    public function get():array
    {
        $path   = str_replace([], [], '/health');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Check Cache Health
     *
     * Check the Appwrite in-memory cache server is up and connection is
     * successful.
     *
     * @throws Exception
     * @return array
     */
    public function getCache():array
    {
        $path   = str_replace([], [], '/health/cache');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Check DB Health
     *
     * Check the Appwrite database server is up and connection is successful.
     *
     * @throws Exception
     * @return array
     */
    public function getDB():array
    {
        $path   = str_replace([], [], '/health/db');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Check the number of pending certificate messages
     *
     * Get the number of certificates that are waiting to be issued against
     * [Letsencrypt](https://letsencrypt.org/) in the Appwrite internal queue
     * server.
     *
     * @throws Exception
     * @return array
     */
    public function getQueueCertificates():array
    {
        $path   = str_replace([], [], '/health/queue/certificates');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Check the number of pending functions messages
     *
     * @throws Exception
     * @return array
     */
    public function getQueueFunctions():array
    {
        $path   = str_replace([], [], '/health/queue/functions');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Check the number of pending log messages
     *
     * Get the number of logs that are waiting to be processed in the Appwrite
     * internal queue server.
     *
     * @throws Exception
     * @return array
     */
    public function getQueueLogs():array
    {
        $path   = str_replace([], [], '/health/queue/logs');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Check the number of pending task messages
     *
     * Get the number of tasks that are waiting to be processed in the Appwrite
     * internal queue server.
     *
     * @throws Exception
     * @return array
     */
    public function getQueueTasks():array
    {
        $path   = str_replace([], [], '/health/queue/tasks');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Check the number of pending usage messages
     *
     * Get the number of usage stats that are waiting to be processed in the
     * Appwrite internal queue server.
     *
     * @throws Exception
     * @return array
     */
    public function getQueueUsage():array
    {
        $path   = str_replace([], [], '/health/queue/usage');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Check number of pending webhook messages
     *
     * Get the number of webhooks that are waiting to be processed in the Appwrite
     * internal queue server.
     *
     * @throws Exception
     * @return array
     */
    public function getQueueWebhooks():array
    {
        $path   = str_replace([], [], '/health/queue/webhooks');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Check Anti virus Health
     *
     * Check the Appwrite Anti Virus server is up and connection is successful.
     *
     * @throws Exception
     * @return array
     */
    public function getStorageAntiVirus():array
    {
        $path   = str_replace([], [], '/health/storage/anti-virus');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Check File System Health
     *
     * Check the Appwrite local storage device is up and connection is successful.
     *
     * @throws Exception
     * @return array
     */
    public function getStorageLocal():array
    {
        $path   = str_replace([], [], '/health/storage/local');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Check Time Health
     *
     * Check the Appwrite server time is synced with Google remote NTP server. We
     * use this technology to smoothly handle leap seconds with no disruptive
     * events. The [Network Time
     * Protocol](https://en.wikipedia.org/wiki/Network_Time_Protocol) (NTP) is
     * used by hundreds of millions of computers and devices to synchronize their
     * clocks over the Internet. If your computer sets its own clock, it likely
     * uses NTP.
     *
     * @throws Exception
     * @return array
     */
    public function getTime():array
    {
        $path   = str_replace([], [], '/health/time');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

}