<?php

namespace Utopia\Logger\Adapter;

use Utopia\Fetch\Client;
use Utopia\Fetch\Exception as FetchException;
use Utopia\Logger\Adapter;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;

// Reference Material
// https://raygun.com/documentation/product-guides/crash-reporting/api/

class Raygun extends Adapter
{
    private const DEFAULT_TIMEOUT = 5;

    private const DEFAULT_CONNECT_TIMEOUT = 1;

    /**
     * @var string (required, can be found in your Raygun application settings)
     */
    protected string $apiKey;

    /**
     * Timeout (seconds) for the complete request.
     */
    protected int $timeout;

    /**
     * Timeout (seconds) for establishing the connection.
     */
    protected int $connectTimeout;

    /**
     * Raygun constructor.
     *
     * @param  string  $key
     * @param  int  $timeout
     * @param  int  $connectTimeout
     */
    public function __construct(string $key, int $timeout = self::DEFAULT_TIMEOUT, int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT)
    {
        $this->apiKey = $key;
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
        return 'raygun';
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
                'category' => $breadcrumb->getCategory(),
                'message' => $breadcrumb->getMessage(),
                'type' => $breadcrumb->getType(),
                'level' => 'request',
                'timestamp' => \intval($breadcrumb->getTimestamp()),
            ]);
        }

        $tagsArray = [];

        foreach ($log->getTags() as $tagKey => $tagValue) {
            \array_push($tagsArray, $tagKey.': '.$tagValue);
        }

        \array_push($tagsArray, 'type: '.$log->getType());
        \array_push($tagsArray, 'environment: '.$log->getEnvironment());
        \array_push($tagsArray, 'sdk: utopia-logger/'.Logger::LIBRARY_VERSION);

        // prepare log (request body)
        $requestBody = [
            'occurredOn' => \intval($log->getTimestamp()),
            'details' => [
                'machineName' => $log->getServer(),
                'groupingKey' => $log->getNamespace(),
                'version' => $log->getVersion(),
                'error' => [
                    'className' => $log->getAction(),
                    'message' => $log->getMessage(),
                ],
                'tags' => $tagsArray,
                'userCustomData' => $log->getExtra(),
                'user' => [
                    'isAnonymous' => empty($log->getUser()),
                    'identifier' => empty($log->getUser()) ? null : $log->getUser()->getId(),
                    'email' => empty($log->getUser()) ? null : $log->getUser()->getEmail(),
                    'fullName' => empty($log->getUser()) ? null : $log->getUser()->getUsername(),
                ],
                'breadcrumbs' => $breadcrumbsArray,
            ],
        ];

        $client = (new Client())
            ->setTimeout($this->timeout * 1000)
            ->setConnectTimeout($this->connectTimeout * 1000)
            ->addHeader('Content-Type', Client::CONTENT_TYPE_APPLICATION_JSON)
            ->addHeader('X-ApiKey', $this->apiKey);

        try {
            $response = $client->fetch(
                url: 'https://api.raygun.com/entries',
                method: Client::METHOD_POST,
                body: $requestBody,
            );
        } catch (FetchException $e) {
            error_log('Raygun push failed with fetch error: '.$e->getMessage());

            return 500;
        }

        $httpCode = $response->getStatusCode();

        if ($httpCode >= 400) {
            error_log("Raygun push failed with status code {$httpCode}: {$response->text()}");
        }

        return $httpCode;
    }

    public function getSupportedTypes(): array
    {
        return [
            Log::TYPE_INFO,
            Log::TYPE_DEBUG,
            Log::TYPE_VERBOSE,
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
            Log::TYPE_VERBOSE,
            Log::TYPE_WARNING,
            Log::TYPE_ERROR,
        ];
    }
}
