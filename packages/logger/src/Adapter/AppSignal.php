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
// https://docs.appsignal.com/api/public-endpoint/errors.html

class AppSignal extends Adapter
{
    private const DEFAULT_TIMEOUT = 5;

    private const DEFAULT_CONNECT_TIMEOUT = 1;

    /**
     * @var string (required, can be found in Appsignal -> Project -> App Settings -> Push & deploy -> Push Key)
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
     * AppSignal constructor.
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
        return 'appSignal';
    }

    /**
     * Push log to external provider
     *
     * @param  Log  $log
     * @return int
     */
    public function push(Log $log): int
    {
        $params = [];

        foreach ($log->getExtra() as $paramKey => $paramValue) {
            $params[$paramKey] = var_export($paramValue, true);
        }

        $breadcrumbsObject = $log->getBreadcrumbs();
        $breadcrumbsArray = [];

        foreach ($breadcrumbsObject as $breadcrumb) {
            \array_push($breadcrumbsArray, [
                'timestamp' => \intval($breadcrumb->getTimestamp()),
                'category' => $breadcrumb->getCategory(),
                'action' => $breadcrumb->getMessage(),
                'metadata' => [
                    'type' => $breadcrumb->getType(),
                ],
            ]);
        }

        $tags = [];

        foreach ($log->getTags() as $tagKey => $tagValue) {
            $tags[$tagKey] = $tagValue;
        }

        if (! empty($log->getType())) {
            $tags['type'] = $log->getType();
        }
        if (! empty($log->getUser()) && ! empty($log->getUser()->getId())) {
            $tags['userId'] = $log->getUser()->getId();
        }
        if (! empty($log->getUser()) && ! empty($log->getUser()->getUsername())) {
            $tags['userName'] = $log->getUser()->getUsername();
        }
        if (! empty($log->getUser()) && ! empty($log->getUser()->getEmail())) {
            $tags['userEmail'] = $log->getUser()->getEmail();
        }

        $tags['sdk'] = 'utopia-logger/'.Logger::LIBRARY_VERSION;

        $requestBody = [
            'timestamp' => \intval($log->getTimestamp()),
            'namespace' => $log->getNamespace(),
            'error' => [
                'name' => $log->getMessage(),
                'message' => $log->getMessage(),
                'backtrace' => [],
            ],
            'environment' => [
                'environment' => $log->getEnvironment(),
                'server' => $log->getServer(),
                'version' => $log->getVersion(),
            ],
            'revision' => $log->getVersion(),
            'action' => $log->getAction(),
            'params' => $params,
            'tags' => $tags,
            'breadcrumbs' => $breadcrumbsArray,
        ];

        $body = \json_encode($requestBody);

        if ($body === false) {
            error_log('AppSignal push failed with fetch error: Failed to encode data to JSON: '.\json_last_error_msg());

            return 500;
        }

        try {
            $response = $this->client->sendRequest(new RequestFactory()->body(
                Method::POST,
                'https://appsignal-endpoint.net/collect?api_key='.$this->apiKey.'&version=1.3.19',
                $body,
                ContentType::JSON,
            ));
        } catch (ClientExceptionInterface|InvalidArgumentException $e) {
            error_log('AppSignal push failed with fetch error: '.$e->getMessage());

            return 500;
        }

        $httpCode = $response->getStatusCode();

        if ($httpCode >= 400) {
            error_log("AppSignal push failed with status code {$httpCode}: {$response->getBody()}");
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
