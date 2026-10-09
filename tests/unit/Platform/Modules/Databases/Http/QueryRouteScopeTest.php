<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Queries\Create as DatabasesQuery;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\XList as DatabasesList;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Queries\Create as DocumentsDBQuery;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\XList as DocumentsDBList;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Queries\Create as TablesDBQuery;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\XList as TablesDBList;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Queries\Create as VectorsDBQuery;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\XList as VectorsDBList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Platform\Action;

require_once __DIR__ . '/../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

final class QueryRouteScopeTest extends TestCase
{
    /**
     * @return \Iterator<string, array{class-string<Action>, class-string<Action>}>
     */
    public static function twins(): \Iterator
    {
        yield 'databases' => [DatabasesQuery::class, DatabasesList::class];
        yield 'documentsdb' => [DocumentsDBQuery::class, DocumentsDBList::class];
        yield 'tablesdb' => [TablesDBQuery::class, TablesDBList::class];
        yield 'vectorsdb' => [VectorsDBQuery::class, VectorsDBList::class];
    }

    /**
     * @param class-string<Action> $query
     * @param class-string<Action> $list
     */
    #[DataProvider('twins')]
    public function testQueryRouteRequiresTheScopeOfItsListTwin(string $query, string $list): void
    {
        $this->assertSame(
            (new $list())->getLabels()['scope'],
            (new $query())->getLabels()['scope'],
            'A POST …/query route reads the same documents as its GET list twin, so an API key must need the same scope for both.',
        );
    }
}
