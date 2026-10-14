<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\Deletes;
use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../../../../app/init.php';

final class DeletesTest extends TestCase
{
    public function testListByGroupVisitsEveryMatchPastOnePage(): void
    {
        $database = $this->createDatabase();
        $targets = \array_map(
            static fn (int $index): Document => new Document(['$id' => 'target-' . $index, 'expired' => false]),
            \range(1, 1002),
        );
        $database->createDocuments('targets', $targets);

        $visited = [];
        $lister = new class () extends Deletes {
            /**
             * @param array<Query> $queries
             */
            public function list(string $collection, array $queries, Database $database, callable $callback): void
            {
                $this->listByGroup($collection, $queries, $database, $callback);
            }
        };

        $lister->list('targets', [Query::orderAsc()], $database, function (Document $target) use (&$visited): void {
            $visited[] = $target->getId();
        });

        $this->assertCount(1002, $visited);
        $this->assertSame($visited, \array_values(\array_unique($visited)));
    }

    private function createDatabase(): Database
    {
        $database = new Database(
            new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])),
            new Cache(new NoCache()),
        );
        $database
            ->setAuthorization(new Authorization())
            ->setDatabase('deletesListByGroup')
            ->setNamespace('deletes_list_by_group_' . \uniqid());
        $database->create();
        $database->createCollection(Collection::create(
            id: 'targets',
            attributes: [Attribute::boolean('expired')],
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            documentSecurity: false,
        ));

        return $database;
    }
}
