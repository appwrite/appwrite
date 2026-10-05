<?php

namespace Utopia\Abuse\Adapters\TimeLimit;

use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Database\Database as UtopiaDB;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Query;

class Database extends TimeLimit
{
    public const COLLECTION = 'abuse';

    public const ATTRIBUTES = [
        [
            '$id' => 'key',
            'type' => UtopiaDB::VAR_STRING,
            'size' => UtopiaDB::LENGTH_KEY,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ], [
            '$id' => 'time',
            'type' => UtopiaDB::VAR_DATETIME,
            'size' => 0,
            'required' => true,
            'signed' => false,
            'array' => false,
            'filters' => ['datetime'],
        ], [
            '$id' => 'count',
            'type' => UtopiaDB::VAR_INTEGER,
            'size' => 11,
            'required' => true,
            'signed' => false,
            'array' => false,
            'filters' => [],
        ],
    ];

    public const INDEXES = [
        [
            '$id' => 'unique1',
            'type' => UtopiaDB::INDEX_UNIQUE,
            'attributes' => ['key', 'time'],
            'lengths' => [],
            'orders' => [],
        ], [
            '$id' => 'index2',
            'type' => UtopiaDB::INDEX_KEY,
            'attributes' => ['time'],
            'lengths' => [],
            'orders' => [],
        ],
    ];

    /**
     * @var UtopiaDB
     */
    protected UtopiaDB $db;

    /**
     * @var int|null
     */
    protected ?int $count = null;

    /**
     * @param  string  $key
     * @param  int  $seconds
     * @param  int  $limit
     * @param  UtopiaDB  $db
     */
    public function __construct(string $key, int $limit, int $seconds, UtopiaDB $db)
    {
        $this->key = $key;
        $now = \time();
        $this->timestamp = (int)($now - ($now % $seconds));
        $this->limit = $limit;
        $this->db = $db;
    }

    /**
     * @throws Duplicate
     * @throws \Exception
     */
    public function setup(): void
    {
        if (! $this->db->exists($this->db->getDatabase())) {
            throw new \Exception('You need to create database before running timelimit setup');
        }

        $attributes = \array_map(function ($attribute) {
            return new Document($attribute);
        }, self::ATTRIBUTES);

        $indexes = \array_map(function ($index) {
            return new Document($index);
        }, self::INDEXES);

        try {
            $this->db->createCollection(
                self::COLLECTION,
                $attributes,
                $indexes
            );
        } catch (Duplicate) {
            // Collection already exists
        }
    }

    /**
     * Check
     *
     * Checks if number of counts is bigger or smaller than current limit
     *
     * @param  string  $key
     * @param  int  $timestamp
     * @return int
     *
     * @throws \Exception
     */
    protected function count(string $key, int $timestamp): int
    {
        if (0 == $this->limit) { // No limit no point for counting
            return 0;
        }

        if (! \is_null($this->count)) { // Get fetched result
            return $this->count;
        }

        $timestamp = $this->toDateTime($timestamp);

        /** @var array<Document> $result */
        $result = $this->db->getAuthorization()->skip(function () use ($key, $timestamp) {
            return $this->db->find(self::COLLECTION, [
                Query::equal('key', [$key]),
                Query::equal('time', [$timestamp]),
            ]);
        });

        $this->count = 0;

        if (\count($result) === 1) { // Unique Index
            $count = $result[0]->getAttribute('count', 0);
            if (\is_numeric($count)) {
                $this->count = intval($count);
            }
        }

        return $this->count;
    }

    /**
     * @param  string  $key
     * @param  int  $timestamp
     * @return void
     *
     * @throws AuthorizationException|Structure|\Exception|\Throwable
     */
    protected function hit(string $key, int $timestamp): void
    {
        if (0 == $this->limit) { // No limit no point for counting
            return;
        }

        $timestamp = $this->toDateTime($timestamp);
        $this->db->getAuthorization()->skip(function () use ($timestamp, $key) {
            $data = $this->db->findOne(self::COLLECTION, [
                Query::equal('key', [$key]),
                Query::equal('time', [$timestamp]),
            ]);

            if ($data->isEmpty()) {
                $data = [
                    '$permissions' => [],
                    'key' => $key,
                    'time' => $timestamp,
                    'count' => 1,
                    '$collection' => self::COLLECTION,
                ];

                try {
                    $this->db->createDocument(self::COLLECTION, new Document($data));
                } catch (Duplicate $e) {
                    // Duplicate in case of race condition
                    $data = $this->db->findOne(self::COLLECTION, [
                        Query::equal('key', [$key]),
                        Query::equal('time', [$timestamp]),
                    ]);

                    if (!$data->isEmpty()) {
                        $count = $data->getAttribute('count', 0);
                        if (\is_numeric($count)) {
                            $this->count = intval($count);
                        }
                        $this->db->increaseDocumentAttribute(self::COLLECTION, $data->getId(), 'count');
                    } else {
                        throw new \Exception('Document Not Found');
                    }
                }
            } else {
                /** @var Document $data */
                $this->db->increaseDocumentAttribute(self::COLLECTION, $data->getId(), 'count');
            }
        });

        $this->count++;
    }

    /**
     * @param  string  $key
     * @param  int  $timestamp
     * @param  int  $value
     * @return void
     *
     * @throws AuthorizationException|Structure|\Exception|\Throwable
     */
    protected function set(string $key, int $timestamp, int $value): void
    {
        $timestamp = $this->toDateTime($timestamp);
        $this->db->getAuthorization()->skip(function () use ($timestamp, $key, $value) {
            $data = $this->db->findOne(self::COLLECTION, [
                Query::equal('key', [$key]),
                Query::equal('time', [$timestamp]),
            ]);

            if ($data->isEmpty()) {
                $data = [
                    '$permissions' => [],
                    'key' => $key,
                    'time' => $timestamp,
                    'count' => $value,
                    '$collection' => self::COLLECTION,
                ];

                try {
                    $this->db->createDocument(self::COLLECTION, new Document($data));
                } catch (Duplicate $e) {
                    // Duplicate in case of race condition - update existing document
                    $data = $this->db->findOne(self::COLLECTION, [
                        Query::equal('key', [$key]),
                        Query::equal('time', [$timestamp]),
                    ]);

                    if (!$data->isEmpty()) {
                        /** @var Document $data */
                        $this->db->updateDocument(self::COLLECTION, $data->getId(), new Document([
                            'count' => $value,
                        ]));
                    } else {
                        throw new \Exception('Unable to find abuse tracking document after race condition handling');
                    }
                }
            } else {
                /** @var Document $data */
                $this->db->updateDocument(self::COLLECTION, $data->getId(), new Document([
                    'count' => $value,
                ]));
            }
        });

        $this->count = $value;
    }

    /**
     * Get abuse logs
     *
     * Return logs with an optional offset and limit
     *
     * @param  int|null  $offset
     * @param  int|null  $limit
     * @return array<Document>
     *
     * @throws \Exception
     */
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        /** @var array<Document> $results */
        $results = $this->db->getAuthorization()->skip(function () use ($offset, $limit) {
            $queries = [];
            $queries[] = Query::orderDesc('');

            if (! \is_null($offset)) {
                $queries[] = Query::offset($offset);
            }
            if (! \is_null($limit)) {
                $queries[] = Query::limit($limit);
            }

            return $this->db->find(self::COLLECTION, $queries);
        });

        return $results;
    }

    /**
     * Delete logs older than $timestamp seconds
     *
     * @param  int  $timestamp
     * @return bool
     *
     * @throws AuthorizationException|\Exception
     */
    public function cleanup(int $timestamp): bool
    {
        $timestamp = $this->toDateTime($timestamp);
        $this->db->getAuthorization()->skip(function () use ($timestamp) {
            do {
                $documents = $this->db->find(self::COLLECTION, [
                    Query::lessThan('time', $timestamp),
                ]);

                foreach ($documents as $document) {
                    $this->db->deleteDocument(self::COLLECTION, $document->getId());
                }
            } while (! empty($documents));
        });

        return true;
    }

    protected function toDateTime(int $timestamp): string
    {
        return DateTime::format((new \DateTime())->setTimestamp($timestamp));
    }
}
