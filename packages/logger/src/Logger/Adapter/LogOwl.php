<?php

namespace Utopia\Logger\Adapter;

use Utopia\Fetch\Client;
use Utopia\Fetch\Exception as FetchException;
use Utopia\Logger\Adapter;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;

// Reference Material
// https://docs.logowl.io/docs/custom-adapter
// https://github.com/jz222/logowl-adapter-nodejs/blob/master/lib/broker/index.js

class LogOwl extends Adapter
{
    private const DEFAULT_TIMEOUT = 5;

    private const DEFAULT_CONNECT_TIMEOUT = 1;

    /**
     * @var string (required, can be found in LogOwl -> All Services -> Project -> Ticket -> Service Ticket)
     */
    protected string $ticket;

    /**
     * @var string (optional, the host where LogOwl is reachable, in case of self-hosted LogOwl could
     *              look like 'https://logowl.example.com'. defaults to 'https://api.logowl.io/logging/')
     */
    protected string $logOwlHost;

    /**
     * Timeout (seconds) for the complete request.
     */
    protected int $timeout;

    /**
     * Timeout (seconds) for establishing the connection.
     */
    protected int $connectTimeout;

    /**
     * LogOwl constructor.
     *
     * @param  string  $ticket
     * @param  string  $host
     * @param  int  $timeout
     * @param  int  $connectTimeout
     */
    public function __construct(string $ticket, string $host = '', int $timeout = self::DEFAULT_TIMEOUT, int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT)
    {
        if (empty($host)) {
            $host = 'https://api.logowl.io/logging/';
        }

        $this->ticket = $ticket;
        $this->logOwlHost = $host;
        $this->timeout = $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
        $this->connectTimeout = $connectTimeout > 0 ? $connectTimeout : self::DEFAULT_CONNECT_TIMEOUT;
    }

    /**
     * Return unique adapter name
     *
     * @return string
     */
    public static function getName(): string
    {
        return 'logOwl';
    }

    /**
     * Return adapter type
     *
     * @return string
     */
    public static function getAdapterType(): string
    {
        return 'utopia-logger';
    }

    /**
     * Return adapter version
     *
     * @return string
     */
    public static function getAdapterVersion(): string
    {
        return Logger::LIBRARY_VERSION;
    }

    /**
     * Push log to external provider
     *
     * @param  Log  $log
     * @return int
     */
    public function push(Log $log): int
    {
        $line = isset($log->getExtra()['line']) ? $log->getExtra()['line'] : '';
        $file = isset($log->getExtra()['file']) ? $log->getExtra()['file'] : '';
        $trace = isset($log->getExtra()['trace']) ? $log->getExtra()['trace'] : '';
        $id = empty($log->getUser()) ? null : $log->getUser()->getId();
        $email = empty($log->getUser()) ? null : $log->getUser()->getEmail();
        $username = empty($log->getUser()) ? null : $log->getUser()->getUsername();

        $breadcrumbsObject = $log->getBreadcrumbs();
        $breadcrumbsArray = [];

        foreach ($breadcrumbsObject as $breadcrumb) {
            \array_push($breadcrumbsArray, [
                'type' => 'log',
                'log' => $breadcrumb->getMessage(),
                'timestamp' => \intval($breadcrumb->getTimestamp()),
            ]);
        }

        // prepare log (request body)
        $requestBody = [
            'ticket' => $this->ticket,
            'message' => $log->getAction(),
            'path' => $file,
            'line' => $line,
            'stacktrace' => $trace,
            'badges' => [
                'environment' => $log->getEnvironment(),
                'namespace' => $log->getNamespace(),
                'version' => $log->getVersion(),
                'message' => $log->getMessage(),
                'id' => $id,
                '$email' => $email,
                '$username' => $username,
            ],
            'type' => $log->getType(),
            'metrics' => [
                'platform' => $log->getServer(),
            ],
            'logs' => $breadcrumbsArray,
            'timestamp' => \intval($log->getTimestamp()),
            'adapter' => [
                'name' => $this->getName(),
                'type' => $this->getAdapterType(),
                'version' => $this->getAdapterVersion(),
            ],
        ];

        $client = (new Client())
            ->setTimeout($this->timeout * 1000)
            ->setConnectTimeout($this->connectTimeout * 1000)
            ->addHeader('Content-Type', Client::CONTENT_TYPE_APPLICATION_JSON);

        try {
            $response = $client->fetch(
                url: $this->logOwlHost.$log->getType(),
                method: Client::METHOD_POST,
                body: $requestBody,
            );
        } catch (FetchException $e) {
            error_log('LogOwl push failed with fetch error: '.$e->getMessage());

            return 500;
        }

        $httpCode = $response->getStatusCode();

        if ($httpCode >= 400) {
            error_log("LogOwl push failed with status code {$httpCode}: {$response->text()}");
        }

        return $httpCode;
    }

    public function getSupportedTypes(): array
    {
        return [
            Log::TYPE_ERROR,
        ];
    }

    public function getSupportedEnvironments(): array
    {
        return [
            Log::ENVIRONMENT_STAGING,
            Log::ENVIRONMENT_PRODUCTION,
        ];
    }

    public function getSupportedBreadcrumbTypes(): array
    {
        return [
            Log::TYPE_INFO,
            Log::TYPE_DEBUG,
            Log::TYPE_WARNING,
            Log::TYPE_ERROR,
        ];
    }
}
