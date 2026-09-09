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
            // The collections under test are created from their canonical definitions,
            // the same way a migration does, so this schema cannot drift from production.
            $collections = require __DIR__ . '/../../../../app/config/collections/platform.php';
            foreach (['rules', 'certificates'] as $id) {
                $this->createCollection(
                    $id,
                    array_map(fn (array $attribute) => new Document($attribute), $collections[$id]['attributes']),
                    array_map(fn (array $index) => new Document($index), $collections[$id]['indexes']),
                );
            }
        } catch (\Throwable $error) {
            $this->delete();
            throw $error;
        }
    }
}
