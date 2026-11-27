<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Build;

use Appwrite\Distribution\Build\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class ResultTest extends TestCase
{
    private const string IDENTIFIER = 'io.example.app';

    public function testParsesTheRunnerContract(): void
    {
        $json = \json_encode([
            'artifact' => 'app-unsigned.aab',
            'type' => 'aab',
            'identifier' => self::IDENTIFIER,
            'versionName' => '1.2.3',
            'versionCode' => 42,
            'toolchain' => [
                'image' => 'appwrite/distribution-android:1.0.0',
                'fastlane' => '2.242.0',
                'java' => '21.0.13.1+2-LTS',
                'gradle' => null,
                'flutter' => '3.41.2',
            ],
        ], JSON_THROW_ON_ERROR);

        $result = Result::parse($json, $this->target(), $this->build(42));

        $this->assertSame(42, $result->versionCode);
        $this->assertSame('1.2.3', $result->versionName);
        $this->assertSame(['fastlane' => '2.242.0', 'java' => '21.0.13.1+2-LTS', 'gradle' => null, 'flutter' => '3.41.2'], $result->toolchain);
    }

    public function testToolchainCannotOverrideServerKeys(): void
    {
        $json = $this->json(['toolchain' => [
            'image' => 'attacker/image:latest',
            'cache' => true,
            'cacheFailed' => false,
            'failed' => [],
            'gradle' => '8.14',
            'extra' => 'value',
        ]]);

        $result = Result::parse($json, $this->target(), $this->build(null));

        $this->assertSame(['gradle' => '8.14'], $result->toolchain);
    }

    public function testMissingToolchainIsEmpty(): void
    {
        $result = Result::parse($this->json(['toolchain' => null]), $this->target(), $this->build(null));

        $this->assertSame([], $result->toolchain);
    }

    #[DataProvider('fallbackVersionNames')]
    public function testEmptyVersionNameFallsBackToTheBuild(mixed $versionName): void
    {
        $result = Result::parse($this->json(['versionName' => $versionName]), $this->target(), $this->build(null, '9.9.9'));

        $this->assertSame('9.9.9', $result->versionName);
    }

    /**
     * @return \Iterator<string, array{mixed}>
     */
    public static function fallbackVersionNames(): \Iterator
    {
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    public function testVersionNameAtTheLimitIsKept(): void
    {
        $versionName = \str_repeat('9', Result::VERSION_NAME);

        $result = Result::parse($this->json(['versionName' => $versionName]), $this->target(), $this->build(null));

        $this->assertSame($versionName, $result->versionName);
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('invalid')]
    public function testRejectsInvalidResults(array $changes, ?int $expected): void
    {
        $this->expectException(\UnexpectedValueException::class);

        Result::parse($this->json($changes), $this->target(), $this->build($expected));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, int|null}>
     */
    public static function invalid(): \Iterator
    {
        yield 'ipa type' => [['type' => 'ipa'], null];
        yield 'missing type' => [['type' => null], null];
        yield 'other identifier' => [['identifier' => 'io.other.app'], null];
        yield 'string version code' => [['versionCode' => '42'], null];
        yield 'float version code' => [['versionCode' => 42.5], null];
        yield 'zero version code' => [['versionCode' => 0], null];
        yield 'negative version code' => [['versionCode' => -1], null];
        yield 'version code past the store maximum' => [['versionCode' => Result::VERSION_CODE + 1], null];
        yield 'version code differs from the build' => [['versionCode' => 43], 42];
        yield 'non-string version name' => [['versionName' => 123], null];
        yield 'array version name' => [['versionName' => ['1.0']], null];
        yield 'version name past the limit' => [['versionName' => \str_repeat('9', Result::VERSION_NAME + 1)], null];
        yield 'toolchain is a string' => [['toolchain' => 'gradle'], null];
        yield 'tool version is a number' => [['toolchain' => ['gradle' => 8]], null];
        yield 'tool version is an object' => [['toolchain' => ['java' => ['version' => '21']]], null];
        yield 'tool version past the limit' => [['toolchain' => ['flutter' => \str_repeat('3', Result::TOOL + 1)]], null];
    }

    #[DataProvider('malformed')]
    public function testRejectsMalformedJson(string $json): void
    {
        $this->expectException(\UnexpectedValueException::class);

        Result::parse($json, $this->target(), $this->build(null));
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function malformed(): \Iterator
    {
        yield 'empty' => [''];
        yield 'truncated' => ['{"type": "aab"'];
        yield 'scalar' => ['42'];
        yield 'version code with a fraction' => ['{"type": "aab", "identifier": "io.example.app", "versionCode": 42.0}'];
        yield 'too deep' => [\str_repeat('[', 20) . \str_repeat(']', 20)];
    }

    public function testRejectsAResultPastTheSizeLimit(): void
    {
        $json = $this->json(['padding' => \str_repeat('a', Result::BYTES)]);

        $this->expectException(\UnexpectedValueException::class);

        Result::parse($json, $this->target(), $this->build(null));
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function json(array $changes): string
    {
        return \json_encode([
            'artifact' => 'app-unsigned.aab',
            'type' => 'aab',
            'identifier' => self::IDENTIFIER,
            'versionName' => '1.0.0',
            'versionCode' => 42,
            'toolchain' => ['image' => 'appwrite/distribution-android:1.0.0'],
            ...$changes,
        ], JSON_THROW_ON_ERROR);
    }

    private function target(): Document
    {
        return new Document(['$id' => 'target', 'identifier' => self::IDENTIFIER]);
    }

    private function build(?int $versionCode, string $versionName = ''): Document
    {
        return new Document(['$id' => 'build', 'versionCode' => $versionCode, 'versionName' => $versionName]);
    }
}
