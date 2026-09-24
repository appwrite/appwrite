<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Get as AttributesGet;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Get as ColumnsGet;
use Appwrite\SDK\Method;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Model\AttributeList;
use Appwrite\Utopia\Response\Model\ColumnList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Platform\Action;

require_once __DIR__ . '/../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

final class GetResponseModelsTest extends TestCase
{
    /**
     * The single-item routes return whichever model matches the stored type, but
     * the SDKs dispatch on the models the route declares. A type missing from the
     * declaration is one every typed SDK fails to decode ("unable to match
     * response to any expected response model"), so the declaration must cover
     * every model the list route can return, in the same order.
     *
     * @return array<string, array{Action, Model, string}>
     */
    public static function routes(): array
    {
        return [
            'getColumn' => [new ColumnsGet(), new ColumnList(), 'columns'],
            'getAttribute' => [new AttributesGet(), new AttributeList(), 'attributes'],
        ];
    }

    #[DataProvider('routes')]
    public function testDeclaresEveryModelTheListReturns(Action $action, Model $list, string $rule): void
    {
        $method = $action->getLabels()['sdk'];
        $this->assertInstanceOf(Method::class, $method);

        $this->assertSame(
            $list->getRules()[$rule]['type'],
            $method->getResponses()[0]->getModel(),
        );
    }
}
