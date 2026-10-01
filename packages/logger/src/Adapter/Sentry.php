<?php

namespace Utopia\Logger\Adapter;

use Exception;
use Utopia\Fetch\Client;
use Utopia\Fetch\Exception as FetchException;
use Utopia\Logger\Adapter;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;

// Reference Material
// https://develop.sentry.dev/sdk/event-payloads/

class Sentry extends Adapter
{
    private const DEFAULT_TIMEOUT = 5;

    private const DEFAULT_CONNECT_TIMEOUT = 1;

    /**
     * @var string (required, this part of Sentry DSN: 'https://{{THIS_PART}}@blabla.ingest.sentry.io/blabla')
     */
    protected string $sentryKey;

    /**
     * @var string (required, this part of Sentry DSN: 'https://blabla@blabla.ingest.sentry.io/{{THIS_PART}}')
     */
    protected string $projectId;

    /**
     * @var string (optional, the host where Sentry is reachable, in case of self-hosted Sentry could
     *              look like 'https://sentry.mycompany.com'. defaults to 'https://sentry.io')
     */
    protected string $sentryHost;

    /**
     * Timeout (seconds) for the complete request.
     */
    protected int $timeout;

    /**
     * Timeout (seconds) for establishing the connection.
     */
    protected int $connectTimeout;

    /**
     * Sentry constructor.
     *
     * @param  string  $projectId
     * @param  string  $key
     * @param  string  $host
     * @param  int  $timeout
     * @param  int  $connectTimeout
     */
    public function __construct(string $projectId, string $key, string $host = '', int $timeout = self::DEFAULT_TIMEOUT, int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT)
    {
        if (empty($host)) {
            $host = 'https://sentry.io';
        }

        $this->sentryHost = $host;
        $this->sentryKey = $key;
        $this->projectId = $projectId;
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
        return 'sentry';
    }

    /**
     * Push log to external provider
     *
     * @param  Log  $log
     * @return int
     */
    public function push(Log $log): int
    {
        $breadcrumbsObject = $log->getBreadcrumbs();
        $breadcrumbsArray = [];

        foreach ($breadcrumbsObject as $breadcrumb) {
            \array_push($breadcrumbsArray, [
                'type' => 'default',
                'level' => $breadcrumb->getType(),
                'category' => $breadcrumb->getCategory(),
                'message' => $breadcrumb->getMessage(),
                'timestamp' => $breadcrumb->getTimestamp(),
            ]);
        }

        $stackFrames = [];

        if (isset($log->getExtra()['detailedTrace'])) {
            $detailedTrace = $log->getExtra()['detailedTrace'];
            if (! is_array($detailedTrace)) {
                throw new Exception('detailedTrace must be an array');
            }
            foreach ($detailedTrace as $trace) {
                if (! is_array($trace)) {
                    throw new Exception('detailedTrace must be an array of arrays');
                }
                \array_push($stackFrames, [
                    'filename' => $trace['file'] ?? '',
                    'lineno' => $trace['line'] ?? 0,
                    'function' => $trace['function'] ?? '',
                ]);
            }
        }

        // Reverse array (because Sentry expects the list to go from the oldest to the newest calls)
        $stackFrames = \array_reverse($stackFrames);

        // prepare log (request body)
        $requestBody = [
            'timestamp' => $log->getTimestamp(),
            'platform' => 'php',
            'level' => 'error',
            'logger' => $log->getNamespace(),
            'transaction' => $log->getAction(),
            'server_name' => $log->getServer(),
            'release' => $log->getVersion(),
            'environment' => $log->getEnvironment(),
            'message' => [
                'message' => $log->getMessage(),
            ],
            'exception' => [
                'values' => [
                    [
                        'type' => $log->getMessage(),
                        'stacktrace' => [
                            'frames' => $stackFrames,
                        ],
                    ],
                ],
            ],
            'tags' => $log->getTags(),
            'extra' => $log->getExtra(),
            'breadcrumbs' => $breadcrumbsArray,
            'user' => empty($log->getUser()) ? null : [
                'id' => $log->getUser()->getId(),
                'email' => $log->getUser()->getEmail(),
                'username' => $log->getUser()->getUsername(),
            ],
        ];

        $client = (new Client())
            ->setTimeout($this->timeout * 1000)
            ->setConnectTimeout($this->connectTimeout * 1000)
            ->addHeader('Content-Type', Client::CONTENT_TYPE_APPLICATION_JSON)
            ->addHeader('X-Sentry-Auth', 'Sentry sentry_version=7, sentry_key='.$this->sentryKey.', sentry_client=utopia-logger/'.Logger::LIBRARY_VERSION);

        try {
            $response = $client->fetch(
                url: $this->sentryHost.'/api/'.$this->projectId.'/store/',
                method: Client::METHOD_POST,
                body: $requestBody,
            );
        } catch (FetchException $e) {
            error_log('Sentry push failed with fetch error: '.$e->getMessage());

            return 500;
        }

        $httpCode = $response->getStatusCode();

        if ($httpCode >= 400) {
            error_log("Sentry push failed with status code {$httpCode}: {$response->text()}");
        }

        return $httpCode;
    }

    public function getSupportedTypes(): array
    {
        return [
            Log::TYPE_INFO,
            Log::TYPE_DEBUG,
            Log::TYPE_WARNING,
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
