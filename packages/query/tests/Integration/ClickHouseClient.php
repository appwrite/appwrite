<?php

namespace Tests\Integration;

use InvalidArgumentException;
use RuntimeException;

class ClickHouseClient
{
    public function __construct(
        private readonly string $host = 'http://localhost:18123',
        private readonly string $database = 'query_test',
    ) {
    }

    /**
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function execute(string $query, array $params = []): array
    {
        $url = $this->host . '/?database=' . urlencode($this->database);

        $placeholderIndex = 0;
        $isInsert = (bool) preg_match('/^\s*INSERT\b/i', $query);

        $placeholderCount = substr_count($query, '?');
        $paramCount = count($params);

        if ($placeholderCount > $paramCount) {
            throw new InvalidArgumentException(sprintf(
                'Query has %d placeholder(s) but only %d param(s) were provided.',
                $placeholderCount,
                $paramCount
            ));
        }

        $sql = preg_replace_callback('/\?/', function () use (&$placeholderIndex, $params, &$url) {
            $key = 'param_p' . $placeholderIndex;
            $value = $params[$placeholderIndex];
            $placeholderIndex++;

            $type = match (true) {
                is_int($value) => 'Int64',
                is_float($value) => 'Float64',
                is_bool($value) => 'UInt8',
                default => 'String',
            };

            $url .= '&param_' . $key . '=' . urlencode((string) $value); // @phpstan-ignore cast.string

            return '{' . $key . ':' . $type . '}';
        }, $query) ?? $query;

        if ($placeholderIndex < $paramCount) {
            throw new InvalidArgumentException(sprintf(
                'Query consumed %d placeholder(s) but %d param(s) were provided.',
                $placeholderIndex,
                $paramCount
            ));
        }

        $hasFormatClause = (bool) preg_match('/\bFORMAT\s+\w+\s*;?\s*$/i', $sql);
        $sqlWithFormat = $hasFormatClause ? $sql : $sql . ' FORMAT JSONEachRow';

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: text/plain\r\n",
                'content' => $isInsert ? $sql : $sqlWithFormat,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('ClickHouse request failed');
        }

        $statusLine = $http_response_header[0] ?? '';
        $statusCode = $this->parseStatusCode($statusLine);
        if ($statusCode === null || $statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf(
                'ClickHouse error (HTTP %s): %s',
                $statusCode !== null ? (string) $statusCode : 'unknown',
                $response
            ));
        }

        $trimmed = trim($response);
        if ($trimmed === '') {
            return [];
        }

        $rows = [];
        foreach (explode("\n", $trimmed) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    public function statement(string $sql): void
    {
        $url = $this->host . '/?database=' . urlencode($this->database);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: text/plain\r\n",
                'content' => $sql,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('ClickHouse request failed');
        }

        $statusLine = $http_response_header[0] ?? '';
        $statusCode = $this->parseStatusCode($statusLine);
        if ($statusCode === null || $statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf(
                'ClickHouse error (HTTP %s): %s',
                $statusCode !== null ? (string) $statusCode : 'unknown',
                $response
            ));
        }
    }

    /**
     * Parses the numeric HTTP status code from a status line like "HTTP/1.1 200 OK".
     * Returns null when the line does not match the expected format.
     */
    private function parseStatusCode(string $statusLine): ?int
    {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
