<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Create as DocumentsDBCreate;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Create as VectorsDBCreate;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Http\Route;

require_once __DIR__ . '/../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

final class CreateTransactionIdTest extends TestCase
{
    /**
     * The generator intersects the request body against each Method's
     * `parameters` list. createDocument / createDocuments must list
     * transactionId or generated SDKs drop it even though the route accepts it.
     *
     * @param list<string> $expected
     */
    #[DataProvider('createRoutes')]
    public function testCreateSdkMethodsExposeTransactionId(Route $route, array $expected): void
    {
        $methods = $route->getLabel('sdk');
        $this->assertIsArray($methods);

        $names = [];
        foreach ($methods as $method) {
            $this->assertInstanceOf(Method::class, $method);
            $names[$method->getMethodName()] = \array_map(
                static fn (Parameter $parameter): string => $parameter->getName(),
                $method->getParameters(),
            );
        }

        foreach ($expected as $methodName) {
            $this->assertArrayHasKey($methodName, $names);
            $this->assertContains(
                'transactionId',
                $names[$methodName],
                $route->getPath() . ' ' . $methodName . ' must expose transactionId',
            );
        }
    }

    /**
     * @return array<string, array{0: Route, 1: list<string>}>
     */
    public static function createRoutes(): array
    {
        Method::$processed = [];
        Method::$errors = [];

        return [
            'documentsdb' => [
                new DocumentsDBCreate(),
                ['createDocument', 'createDocuments'],
            ],
            'vectorsdb' => [
                new VectorsDBCreate(),
                ['createDocument', 'createDocuments'],
            ],
        ];
    }
}
