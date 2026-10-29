<?php

declare(strict_types=1);

namespace Utopia\Abuse\Tests\TimeLimit;

use Appwrite\AppwriteException;
use Appwrite\Client;

/**
 * Serves one abuse row and runs a competing request once, just before the next
 * increment reaches the server.
 */
final class RacingTablesDBClient extends Client
{
    public ?\Closure $race = null;

    public function __construct(public int $count)
    {
        parent::__construct();
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    #[\Override]
    public function call(string $method, string $path = '', array $headers = [], array $params = [], ?string $responseType = null): array
    {
        if ($method === self::METHOD_GET && str_ends_with($path, '/rows')) {
            return ['total' => 1, 'rows' => [$this->row()]];
        }

        if ($method === self::METHOD_PATCH && str_ends_with($path, '/count/increment')) {
            $race = $this->race;
            $this->race = null;
            if ($race instanceof \Closure) {
                $race();
            }

            $max = $params['max'] ?? null;
            if (\is_numeric($max) && $this->count + 1 > $max) {
                throw new AppwriteException('Column "count" has reached the maximum value of ' . $max, 400, 'column_limit_exceeded');
            }
            $this->count++;

            return $this->row();
        }

        throw new \LogicException("Unexpected request: {$method} {$path}");
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            '$id' => 'row',
            '$sequence' => '1',
            '$tableId' => 'Abuse',
            '$databaseId' => 'abuse',
            '$createdAt' => '',
            '$updatedAt' => '',
            '$permissions' => [],
            'count' => $this->count,
        ];
    }
}
