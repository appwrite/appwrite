<?php

namespace Utopia\Domains;

abstract class Adapter
{
    protected string $userAgent = 'Utopia PHP Framework';

    /** @var array<mixed> */
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
     * @retury array|string
     *
     * @throws \Exception
     */
    public function call(string $method, string $path = '', array|string $params = [], array $headers = []): array|string
    {
        $headers = array_merge($this->headers, $headers);
        $ch = curl_init(
            (
                str_contains($path, 'http')
                ? $path
                : $this->endpoint . $path . (
                    ($method === 'GET' && !\in_array($params, ['', '0', []], true) && $headers['Content-Type'] != 'text/xml')
                    ? '?' . http_build_query($params)
                    : ''
                )
            ),
        );

        $responseHeaders = [];
        $responseStatus = -1;
        $responseType = '';
        $responseBody = '';

        $query = null;

        if (!\in_array($params, ['', '0', []], true)) {
            $query = match ($headers['Content-Type']) {
                'application/json' => json_encode($params, JSON_UNESCAPED_SLASHES),
                'multipart/form-data' => $this->flatten($params),
                'text/xml' => $params,
                default => http_build_query($params),
            };
        }

        foreach ($headers as $i => $header) {
            $headers[] = $i . ':' . $header;

            unset($headers[$i]);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, php_uname('s') . '-' . php_uname('r') . ':php-' . phpversion());
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, string $header) use (&$responseHeaders): int {
            $len = \strlen($header);
            $header = explode(':', strtolower($header), 2);

            if (\count($header) < 2) { // ignore invalid headers
                return $len;
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }

        $responseBody = curl_exec($ch);

        $responseType = $responseHeaders['content-type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (substr($responseType, 0, strpos($responseType, ';')) === 'application/json') {
            $responseBody = json_decode($responseBody, true);
        }

        if (curl_errno($ch) !== 0) {
            throw new \Exception(curl_error($ch));
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
     * Flatten params array to PHP multiple format
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $output = [];

        foreach ($data as $key => $value) {
            $finalKey = $prefix !== '' && $prefix !== '0' ? "{$prefix}[{$key}]" : $key;

            if (\is_array($value)) {
                $output += $this->flatten($value, $finalKey); // @todo: handle name collision here if needed
            } else {
                $output[$finalKey] = $value;
            }
        }

        return $output;
    }
}
