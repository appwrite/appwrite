<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Tasks;

use Appwrite\Platform\Tasks\SDKs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\OpenAPI\Parser;

final class SDKsTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function versions(): \Iterator
    {
        yield 'release' => ['2.0.0', '2.0.x'];
        yield 'patch' => ['2.4.3', '2.4.x'];
    }

    #[DataProvider('versions')]
    public function testGetServerVersionUsesSpecificationVersion(string $version, string $expected): void
    {
        $specification = Parser::parse([
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Appwrite',
                'version' => $version,
            ],
            'paths' => [],
        ]);

        $this->assertSame($expected, SDKs::getServerVersion($specification));
    }
}
