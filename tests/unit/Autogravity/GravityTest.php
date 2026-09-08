<?php

declare(strict_types=1);

namespace Tests\Unit\Autogravity;

use Appwrite\Autogravity\Exception;
use Appwrite\Autogravity\Gravity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GravityTest extends TestCase
{
    public function testRejectsInvalidCoordinates(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Autogravity returned an invalid gravity');

        new Gravity(1.1, 0.5);
    }

    /**
     * @return \Iterator<string, array{int, float, float}>
     */
    public static function rotationProvider(): \Iterator
    {
        yield 'clockwise' => [90, 0.8, 0.75];
        yield 'upside down' => [180, 0.75, 0.2];
        yield 'counterclockwise' => [-90, 0.2, 0.25];
    }

    #[DataProvider('rotationProvider')]
    public function testUnrotate(int $rotation, float $expectedX, float $expectedY): void
    {
        $gravity = (new Gravity(0.25, 0.8))->unrotate($rotation);

        $this->assertEqualsWithDelta($expectedX, $gravity->x, 0.000001);
        $this->assertEqualsWithDelta($expectedY, $gravity->y, 0.000001);
    }

    public function testGetArrayCopy(): void
    {
        $this->assertSame(['x' => 0.2, 'y' => 0.8], (new Gravity(0.2, 0.8))->getArrayCopy());
    }
}
