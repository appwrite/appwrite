<?php

namespace Utopia\Abuse\Adapter\TimeLimit;

use Utopia\Abuse\Adapter\TimeLimit;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database as UtopiaDB;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Index;
use Utopia\Database\Query;

final readonly class Database extends TimeLimit
{
    public const string COLLECTION = 'abuse';

    public function __construct(string $key, int $limit, int $seconds, private UtopiaDB $db)
    {
        parent::__construct($key, $limit, $seconds);
    }

    /**
     * @return list<Attribute>
     */
    public static function attributes(): array
    {
        return [
            Attribute::string(key: 'key', size: UtopiaDB::LENGTH_KEY, required: true),
            Attribute::datetime(key: 'time', required: true, signed: false, filters: ['datetime']),
            Attribute::integer(key: 'count', size: 11, required: true, signed: false),
        ];
    }

    /**
     * @return list<Index>
     */
    public static function indexes(): array
    {
        return [
            Index::unique(key: 'unique1', attributes: ['key', 'time']),
            Index::key(key: 'index2', attributes: ['time']),
        ];
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

        try {
            $this->db->createCollection(new Collection(id: self::COLLECTION, attributes: self::attributes(), indexes: self::indexes()));
        } catch (Duplicate) {
            // Collection already exists
        }
    }

    /**
     * @throws AuthorizationException|Structure|\Exception|\Throwable
     */
    #[\Override]
    protected function hit(string $key, int $window): int
    {
        $time = $this->toDateTime($window);

        return $this->db->getAuthorization()->skip(function () use ($key, $time): int {
            $document = $this->find($key, $time);

            if ($document->isEmpty()) {
                try {
                    $this->db->createDocument(self::COLLECTION, new Document([
                        '$permissions' => [],
                        'key' => $key,
                        'time' => $time,
                        'count' => 1,
                        '$collection' => self::COLLECTION,
                    ]));

                    return 0;
                } catch (Duplicate) {
                    // Duplicate in case of race condition
                    $document = $this->find($key, $time);

                    if ($document->isEmpty()) {
                        throw new \Exception('Document Not Found');
                    }
                }
            }

            $count = $this->parseCount($document->getAttribute('count', 0));

            if ($count >= $this->limit) {
                return $count;
            }

            $this->db->increaseDocumentAttribute(self::COLLECTION, $document->getId(), 'count');

            return $count;
        });
    }

    /**
     * @throws \Exception
     */
    #[\Override]
    protected function count(string $key, int $window): int
    {
        $time = $this->toDateTime($window);

        $document = $this->db->getAuthorization()->skip(fn (): Document => $this->find($key, $time));

        return $this->parseCount($document->getAttribute('count', 0));
    }

    /**
     * @throws AuthorizationException|Structure|\Exception|\Throwable
     */
    #[\Override]
    protected function set(string $key, int $window, int $value): void
    {
        $time = $this->toDateTime($window);

        $this->db->getAuthorization()->skip(function () use ($key, $time, $value): void {
            $document = $this->find($key, $time);

            if ($document->isEmpty()) {
                try {
                    $this->db->createDocument(self::COLLECTION, new Document([
                        '$permissions' => [],
                        'key' => $key,
                        'time' => $time,
                        'count' => $value,
                        '$collection' => self::COLLECTION,
                    ]));

                    return;
                } catch (Duplicate) {
                    // Duplicate in case of race condition - update existing document
                    $document = $this->find($key, $time);

                    if ($document->isEmpty()) {
                        throw new \Exception('Unable to find abuse tracking document after race condition handling');
                    }
                }
            }

            $this->db->updateDocument(self::COLLECTION, $document->getId(), new Document([
                'count' => $value,
            ]));
        });
    }

    /**
     * @return array<Document>
     *
     * @throws \Exception
     */
    #[\Override]
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        return $this->db->getAuthorization()->skip(function () use ($offset, $limit): array {
            $queries = [Query::orderDesc('')];

            if (! \is_null($offset)) {
                $queries[] = Query::offset($offset);
            }
            if (! \is_null($limit)) {
                $queries[] = Query::limit($limit);
            }

            return $this->db->find(self::COLLECTION, $queries);
        });
    }

    /**
     * @throws AuthorizationException|\Exception
     */
    #[\Override]
    public function cleanup(int $timestamp): bool
    {
        $time = $this->toDateTime($timestamp);

        $this->db->getAuthorization()->skip(function () use ($time): void {
            do {
                $documents = $this->db->find(self::COLLECTION, [
                    Query::lessThan('time', $time),
                ]);

                foreach ($documents as $document) {
                    $this->db->deleteDocument(self::COLLECTION, $document->getId());
                }
            } while (! empty($documents));
        });

        return true;
    }

    private function find(string $key, string $time): Document
    {
        return $this->db->findOne(self::COLLECTION, [
            Query::equal('key', [$key]),
            Query::equal('time', [$time]),
        ]);
    }

    private function parseCount(mixed $count): int
    {
        return \is_numeric($count) ? (int) $count : 0;
    }

    private function toDateTime(int $timestamp): string
    {
        return DateTime::format(new \DateTime()->setTimestamp($timestamp));
    }
}
