<?php

declare(strict_types=1);

namespace Tests\Unit\Maintenance;

use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

/**
 * A platform database holding only the projects collection, seeded with the attributes a sweep reads.
 */
final class Projects
{
    /**
     * @param list<array{0: string, 1: int, 2: ?\DateTimeImmutable, 3: string}> $rows id, sequence, accessedAt, region
     */
    public static function seed(Adapter $adapter, array $rows): Database
    {
        $authorization = new Authorization();
        $authorization->disable();

        $database = (new Database($adapter, new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('_console')
            ->setAuthorization($authorization);
        $database->create();

        // Projects are written with APP_PROJECTS_SUBQUERIES skipped and Sweep selects none of them, so no
        // collection a subquery joins has to exist, whichever subqueries the projects collection declares.
        $collections = Config::getParam('collections', [])['console'];
        $database->createCollection(Collection::create(
            id: 'projects',
            attributes: $collections['projects']['attributes'],
            indexes: $collections['projects']['indexes'],
        ));

        $timezone = new \DateTimeZone(\date_default_timezone_get());

        $database->skipValidation(function () use ($database, $rows, $timezone): void {
            foreach ($rows as [$id, $sequence, $accessedAt, $region]) {
                $database->skipFilters(fn (): Document => $database->createDocument('projects', new Document([
                    '$id' => $id,
                    '$sequence' => (string) $sequence,
                    'name' => $id,
                    'region' => $region,
                    'accessedAt' => $accessedAt === null ? null : DateTime::format(\DateTime::createFromImmutable($accessedAt)->setTimezone($timezone)),
                    'teamId' => 'team',
                    'teamInternalId' => '1',
                    'database' => 'database_db_' . $region,
                ])), APP_PROJECTS_SUBQUERIES);
            }
        });

        return $database;
    }
}
