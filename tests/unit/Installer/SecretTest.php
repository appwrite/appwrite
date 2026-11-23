<?php

declare(strict_types=1);

namespace Tests\Unit\Installer;

use Appwrite\Installer\Secret;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretTest extends TestCase
{
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = \getenv(Secret::ENVIRONMENT);
    }

    protected function tearDown(): void
    {
        \putenv($this->previous === false ? Secret::ENVIRONMENT : Secret::ENVIRONMENT . '=' . $this->previous);
    }

    #[DataProvider('candidates')]
    public function testMatches(string $secret, string $candidate, bool $expected): void
    {
        $this->assertSame($expected, (new Secret($secret))->matches($candidate));
    }

    /**
     * @return \Iterator<string, array{0: string, 1: string, 2: bool}>
     */
    public static function candidates(): \Iterator
    {
        yield 'issued secret' => ['issued-secret', 'issued-secret', true];
        yield 'wrong candidate' => ['issued-secret', 'wrong', false];
        yield 'missing candidate' => ['issued-secret', '', false];
        yield 'nothing issued' => ['', '', false];
        yield 'nothing issued, any candidate' => ['', 'anything', false];
    }

    public function testGenerateIsRandom(): void
    {
        $first = Secret::generate()->value;

        $this->assertSame(64, \strlen($first));
        $this->assertTrue(\ctype_xdigit($first));
        $this->assertNotSame($first, Secret::generate()->value);
    }

    public function testFromEnvironmentUsesTheLaunchingProcessSecret(): void
    {
        \putenv(Secret::ENVIRONMENT . '=from-parent');

        $this->assertTrue(Secret::fromEnvironment()->matches('from-parent'));
    }

    public function testFromEnvironmentGeneratesWhenUnset(): void
    {
        \putenv(Secret::ENVIRONMENT);

        $secret = Secret::fromEnvironment();

        $this->assertSame(64, \strlen($secret->value));
        $this->assertTrue($secret->matches($secret->value));
    }
}
