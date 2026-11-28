<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Lease;

use Appwrite\Distribution\Lease\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class ResultTest extends TestCase
{
    private const string IDENTIFIER = 'io.example.app';

    private const string TEAM = 'TEAM123456';

    private const string DIGEST = 'ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12';

    public function testParsesTheAgentContract(): void
    {
        $result = Result::parse($this->content(), $this->target(), $this->build('1.2.3'), $this->identity());

        $this->assertSame(self::DIGEST, $result->digest);
        $this->assertSame(1048576, $result->size);
        $this->assertSame(self::IDENTIFIER, $result->identifier);
        $this->assertSame('1.2.3', $result->versionName);
        $this->assertSame('42', $result->buildNumber);
        $this->assertSame(self::TEAM, $result->teamId);
        $this->assertTrue($result->signed);
        $this->assertSame(['aps-environment' => 'production'], $result->entitlements);
        $this->assertSame([
            'image' => 'macos-15-xcode-16.2',
            'fastlane' => '2.228.0',
            'xcode' => '16.2',
            'flutter' => null,
        ], $result->toolchain);
    }

    public function testAcceptsTheAgentVersionNameWhenTheBuildHasNone(): void
    {
        $result = Result::parse($this->content(['versionName' => '4.5.6']), $this->target(), $this->build(''), $this->identity());

        $this->assertSame('4.5.6', $result->versionName);
    }

    public function testAcceptsAnIntegerBuildNumber(): void
    {
        $result = Result::parse($this->content(['buildNumber' => 42]), $this->target(), $this->build('1.2.3'), $this->identity());

        $this->assertSame('42', $result->buildNumber);
    }

    public function testNormalizesTheDigestToLowercase(): void
    {
        $result = Result::parse($this->content(['digest' => \strtoupper(self::DIGEST)]), $this->target(), $this->build('1.2.3'), $this->identity());

        $this->assertSame(self::DIGEST, $result->digest);
    }

    public function testDropsUnknownToolsAndDefaultsMissingFields(): void
    {
        $result = Result::parse($this->content([
            'entitlements' => null,
            'toolchain' => ['xcode' => '16.2', 'java' => '21', 'cache' => true],
        ]), $this->target(), $this->build('1.2.3'), $this->identity());

        $this->assertSame([], $result->entitlements);
        $this->assertSame(['xcode' => '16.2'], $result->toolchain);
    }

    public function testMissingToolchainIsEmpty(): void
    {
        $content = $this->content();
        unset($content['toolchain'], $content['entitlements']);

        $result = Result::parse($content, $this->target(), $this->build('1.2.3'), $this->identity());

        $this->assertSame([], $result->toolchain);
        $this->assertSame([], $result->entitlements);
    }

    /**
     * @return \Iterator<string, array{mixed, string}>
     */
    public static function mismatches(): \Iterator
    {
        yield 'not an object' => ['{"type":"ipa"}', '1.2.3'];
        yield 'null' => [null, '1.2.3'];
        yield 'not an ipa' => [['type' => 'aab'], '1.2.3'];
        yield 'missing type' => [['type' => null], '1.2.3'];
        yield 'short digest' => [['digest' => \substr(self::DIGEST, 1)], '1.2.3'];
        yield 'non-hex digest' => [['digest' => \str_repeat('g', 64)], '1.2.3'];
        yield 'missing digest' => [['digest' => null], '1.2.3'];
        yield 'zero size' => [['size' => 0], '1.2.3'];
        yield 'negative size' => [['size' => -1], '1.2.3'];
        yield 'string size' => [['size' => '1048576'], '1.2.3'];
        yield 'another identifier' => [['identifier' => 'io.example.other'], '1.2.3'];
        yield 'another build number' => [['buildNumber' => '43'], '1.2.3'];
        yield 'missing build number' => [['buildNumber' => null], '1.2.3'];
        yield 'float build number' => [['buildNumber' => 42.0], '1.2.3'];
        yield 'another version name' => [['versionName' => '1.2.4'], '1.2.3'];
        yield 'missing version name' => [['versionName' => null], '1.2.3'];
        yield 'empty version name for a build without one' => [['versionName' => ''], ''];
        yield 'missing version name for a build without one' => [['versionName' => null], ''];
        yield 'version name over 64 bytes for a build without one' => [['versionName' => \str_repeat('1', 65)], ''];
        yield 'another team' => [['teamId' => 'OTHERTEAM1'], '1.2.3'];
        yield 'missing team' => [['teamId' => null], '1.2.3'];
        yield 'unsigned' => [['signed' => false], '1.2.3'];
        yield 'signed as a string' => [['signed' => 'true'], '1.2.3'];
        yield 'missing signed' => [['signed' => null], '1.2.3'];
        yield 'entitlements not an object' => [['entitlements' => 'aps'], '1.2.3'];
        yield 'toolchain not an object' => [['toolchain' => 'xcode'], '1.2.3'];
        yield 'tool version not a string' => [['toolchain' => ['xcode' => 16]], '1.2.3'];
        yield 'tool version over 1024 bytes' => [['toolchain' => ['xcode' => \str_repeat('1', 1025)]], '1.2.3'];
    }

    #[DataProvider('mismatches')]
    public function testRejects(mixed $override, string $versionName): void
    {
        $content = \is_array($override) ? $this->content($override) : $override;

        $this->expectException(\UnexpectedValueException::class);

        Result::parse($content, $this->target(), $this->build($versionName), $this->identity());
    }

    public function testRejectsAnIdentityWithoutATeam(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        Result::parse($this->content(['teamId' => '']), $this->target(), $this->build('1.2.3'), new Document(['teamId' => '']));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function content(array $overrides = []): array
    {
        return \array_replace([
            'artifact' => 'app.ipa',
            'type' => 'ipa',
            'digest' => self::DIGEST,
            'size' => 1048576,
            'identifier' => self::IDENTIFIER,
            'versionName' => '1.2.3',
            'versionCode' => 42,
            'buildNumber' => '42',
            'teamId' => self::TEAM,
            'signed' => true,
            'entitlements' => ['aps-environment' => 'production'],
            'toolchain' => [
                'image' => 'macos-15-xcode-16.2',
                'fastlane' => '2.228.0',
                'xcode' => '16.2',
                'flutter' => null,
            ],
        ], $overrides);
    }

    private function target(): Document
    {
        return new Document(['$id' => 'target1', 'platform' => 'ios', 'identifier' => self::IDENTIFIER]);
    }

    private function build(string $versionName): Document
    {
        return new Document(['$id' => 'build1', 'versionName' => $versionName, 'buildNumber' => '42']);
    }

    private function identity(): Document
    {
        return new Document(['$id' => 'identity1', 'platform' => 'ios', 'teamId' => self::TEAM]);
    }
}
