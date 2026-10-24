<?php

namespace Appwrite\Utopia\Fetch\Adapter;

use Utopia\Fetch\Adapter;
use Utopia\Fetch\Chunk;
use Utopia\Fetch\Exception;
use Utopia\Fetch\Options\Request as RequestOptions;
use Utopia\Fetch\Response;

/**
 * cURL adapter that aborts the transfer once the response body passes a size cap.
 */
class Curl implements Adapter
{
    public function __construct(private readonly int $maxSize)
    {
    }

    public function send(
        string $url,
        string $method,
        mixed $body,
        array $headers,
        RequestOptions $options,
        ?callable $chunkCallback = null
    ): Response {
        $handle = \curl_init();
        if ($handle === false) {
            throw new Exception('Failed to initialize cURL handle');
        }

        $responseHeaders = [];
        $responseBody = '';
        $received = 0;
        $chunkIndex = 0;
        $maxSize = $this->maxSize;

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => \array_map(fn (string $key, string $value): string => $key . ':' . $value, \array_keys($headers), $headers),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADERFUNCTION => function ($curl, string $header) use (&$responseHeaders): int {
                $length = \strlen($header);
                $parts = \explode(':', $header, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[\strtolower(\trim($parts[0]))] = \trim($parts[1]);
                }

                return $length;
            },
            // Returning a short count makes cURL abort the transfer with a write error.
            CURLOPT_WRITEFUNCTION => function ($curl, string $data) use ($chunkCallback, &$responseBody, &$received, &$chunkIndex, $maxSize): int {
                $size = \strlen($data);
                $received += $size;
                if ($received > $maxSize) {
                    return 0;
                }

                if ($chunkCallback !== null) {
                    $chunkCallback(new Chunk(data: $data, size: $size, timestamp: \microtime(true), index: $chunkIndex++));
                } else {
                    $responseBody .= $data;
                }

                return $size;
            },
            CURLOPT_MAXFILESIZE_LARGE => $maxSize,
            CURLOPT_CONNECTTIMEOUT_MS => $options->getConnectTimeout(),
            CURLOPT_TIMEOUT_MS => $options->getTimeout(),
            CURLOPT_MAXREDIRS => $options->getMaxRedirects(),
            CURLOPT_FOLLOWLOCATION => $options->getAllowRedirects(),
            CURLOPT_USERAGENT => $options->getUserAgent(),
        ];

        if ($body !== null && $body !== [] && $body !== '') {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }

        \curl_setopt_array($handle, $curlOptions);

        if (\curl_exec($handle) === false) {
            $error = $received > $maxSize
                ? 'Response body is larger than ' . $maxSize . ' bytes.'
                : \curl_error($handle);
            throw new Exception($error);
        }

        return new Response(
            statusCode: \curl_getinfo($handle, CURLINFO_HTTP_CODE),
            headers: $responseHeaders,
            body: $responseBody,
        );
    }
}
