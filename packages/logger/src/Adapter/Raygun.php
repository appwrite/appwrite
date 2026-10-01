<?php

namespace Utopia\Logger\Adapter;

use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Logger\Adapter;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

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
     * PSR-18 client the log is pushed through.
     */
    protected ClientInterface $client;

    /**
     * Raygun constructor.
     *
     * @param  string  $key
     * @param  int  $timeout
     * @param  int  $connectTimeout
     * @param  ClientInterface|null  $client  PSR-18 client (default: cURL with the timeouts above, redirects followed)
     */
    public function __construct(string $key, int $timeout = self::DEFAULT_TIMEOUT, int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT, ?ClientInterface $client = null)
    {
        $this->apiKey = $key;
        $this->timeout = $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
        $this->connectTimeout = $connectTimeout > 0 ? $connectTimeout : self::DEFAULT_CONNECT_TIMEOUT;
        $this->client = $client ?? new Client(new CurlAdapter())
            ->withTimeout($this->timeout)
            ->withConnectTimeout($this->connectTimeout)
            ->withFollowRedirects();
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

        $body = \json_encode($requestBody);

        if ($body === false) {
            error_log('Raygun push failed with fetch error: Failed to encode data to JSON: '.\json_last_error_msg());

            return 500;
        }

        try {
            $response = $this->client->sendRequest(new RequestFactory()->body(
                Method::POST,
                'https://api.raygun.com/entries',
                $body,
                ContentType::JSON,
                ['X-ApiKey' => $this->apiKey],
            ));
        } catch (ClientExceptionInterface|InvalidArgumentException $e) {
            error_log('Raygun push failed with fetch error: '.$e->getMessage());

            return 500;
        }

        $httpCode = $response->getStatusCode();

        if ($httpCode >= 400) {
            error_log("Raygun push failed with status code {$httpCode}: {$response->getBody()}");
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
