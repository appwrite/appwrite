<?php

declare(strict_types=1);

namespace Tests\E2E\General\Certificates;

use PHPUnit\Framework\Assert;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document;
use Utopia\Database\PDO;
use Utopia\Database\Validator\Authorization;

final class Database extends UtopiaDatabase
{
    /** @var array<array{string, string, array<string, mixed>}> */
    public array $writes = [];

    public function __construct()
    {
        if (getenv('_APP_DB_ADAPTER') !== 'postgresql' || getenv('_APP_DB_USER') === false) {
            Assert::markTestSkipped('Requires the PostgreSQL adapter and configured _APP_DB_* credentials.');
        }
        $options = Postgres::getPDOAttributes();
        $options[\PDO::ATTR_PERSISTENT] = false;
        $dsn = 'pgsql:host=' . (getenv('_APP_DB_HOST') ?: 'postgresql')
            . ';port=' . (getenv('_APP_DB_PORT') ?: '5432')
            . ';dbname=' . (getenv('_APP_DB_SCHEMA') ?: 'postgres');
        $pdo = new PDO($dsn, getenv('_APP_DB_USER') ?: null, getenv('_APP_DB_PASS') ?: null, $options);
        parent::__construct(new Postgres($pdo), new Cache(new NoCache()));
        $authorization = new Authorization();
        $authorization->disable();
        $this->setAuthorization($authorization);
        $this->setDatabase('certificate_test_' . bin2hex(random_bytes(6)))->setNamespace('test')->setPreserveDates(true);
        $this->create();
        try {
            $attributes = [
                'rules' => [
                    'projectId' => 255, 'projectInternalId' => 255, 'domain' => 255,
                    'type' => 32, 'region' => 16, 'certificateId' => 255, 'status' => 255,
                    'deploymentResourceType' => 32, 'owner' => 16, 'search' => 16384,
                    'logs' => 1000000,
                ],
                'certificates' => ['domain' => 255, 'logs' => 1000000],
                'projects' => ['region' => 128],
            ];
            foreach ($attributes as $collection => $strings) {
                $this->createCollection($collection);
                foreach ($strings as $attribute => $size) {
                    $default = $collection === 'rules' && \in_array($attribute, ['deploymentResourceType', 'owner', 'logs'], true) ? '' : null;
                    $this->createAttribute($collection, $attribute, self::VAR_STRING, $size, false, $default);
                }
            }
            $this->createAttribute('certificates', 'attempts', self::VAR_INTEGER, 0, false);
            foreach (['updated', 'issueDate', 'renewDate'] as $attribute) {
                $this->createAttribute('certificates', $attribute, self::VAR_DATETIME, 0, false);
            }
            $this->createAttribute('projects', 'accessedAt', self::VAR_DATETIME, 0, false);
            $this->createIndex('rules', 'domain', self::INDEX_UNIQUE, ['domain']);
            $this->createIndex('certificates', 'domain', self::INDEX_KEY, ['domain']);
        } catch (\Throwable $error) {
            $this->delete();
            throw $error;
        }
    }

    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        $this->writes[] = [$collection, $id, $document->getArrayCopy()];
        return parent::updateDocument($collection, $id, $document);
    }
}
