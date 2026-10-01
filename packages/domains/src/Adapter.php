<?php

namespace Utopia\Domains;

use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\Client\Destination\Anywhere;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\Psr7\Stream\Factory as StreamFactory;

abstract class Adapter
{
    protected string $userAgent = 'Utopia PHP Framework';

    /** @var array<string, string> */
    protected array $headers;

    /**
     * __construct
     * Instantiate a new adapter.
     */
    public function __construct(protected string $endpoint, protected string $apiKey, protected string $apiSecret)
    {
        $this->headers = [
            'Authorization' => 'sso-key ' . $this->apiKey . ':' . $this->apiSecret,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Call
     *
     * Make an API call
     *
     * @param array<string, string> $headers
     *
     * @retury array|string
     *
     * @throws \Exception
     */
    public function call(string $method, string $path = '', array|string $params = [], array $headers = []): array|string
    {
        $headers = array_merge($this->headers, $headers);
        $url = str_contains($path, 'http')
            ? $path
            : $this->endpoint . $path . (
                ($method === 'GET' && !\in_array($params, ['', '0', []], true) && $headers['Content-Type'] != 'text/xml')
                ? '?' . http_build_query($params)
                : ''
            );

        $query = '';

        if (!\in_array($params, ['', '0', []], true)) {
            $query = match ($headers['Content-Type']) {
                'application/json' => json_encode($params, JSON_UNESCAPED_SLASHES),
                'text/xml' => $params,
                default => http_build_query($params),
            };
        }

        $factory = new RequestFactory();

        if ($method !== 'GET' && $headers['Content-Type'] === 'multipart/form-data' && \is_array($params)) {
            // The factory sets Content-Type itself, with the body's boundary
            unset($headers['Content-Type']);
            $request = $factory->multipart($method, $url, $this->flatten($params), $headers);
        } else {
            $request = $factory->createRequest($method, $url);

            foreach ($headers as $name => $header) {
                $request = $request->withHeader($name, $header);
            }

            if ($method !== 'GET') {
                $request = $request->withBody(new StreamFactory()->createStream(\is_string($query) ? $query : ''));
            }
        }

        $client = new Client(new CurlAdapter(options: [
            CURLOPT_ENCODING => null,
            CURLOPT_USERAGENT => php_uname('s') . '-' . php_uname('r') . ':php-' . phpversion(),
        ]), new Anywhere())->withFollowRedirects();

        try {
            $response = $client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new \Exception($e->getMessage());
        }

        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[strtolower($name)] = strtolower((string) end($values));
        }

        $responseType = $responseHeaders['content-type'] ?? '';
        $responseStatus = $response->getStatusCode();
        $responseBody = (string) $response->getBody();

        if (substr($responseType, 0, strpos($responseType, ';')) === 'application/json') {
            $responseBody = json_decode($responseBody, true);
        }

        if ($responseStatus >= 400) {
            if (\is_array($responseBody)) {
                throw new \Exception(json_encode($responseBody));
            }
            throw new \Exception($responseStatus . ': ' . $responseBody);

        }

        return $responseBody;
    }

    /**
     * Flatten params to PHP's nested field names (a[b][c])
     *
     * @param array<mixed> $data
     * @return array<string, string>
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $output = [];

        foreach ($data as $key => $value) {
            $finalKey = $prefix !== '' ? "{$prefix}[{$key}]" : (string) $key;

            if (\is_array($value)) {
                $output += $this->flatten($value, $finalKey);
            } else {
                $output[$finalKey] = \is_scalar($value) ? (string) $value : '';
            }
        }

        return $output;
    }
}
