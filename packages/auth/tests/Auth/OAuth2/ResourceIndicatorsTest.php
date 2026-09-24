<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\InvalidResourceException;
use Utopia\Auth\OAuth2\ResourceIndicators;

final class ResourceIndicatorsTest extends TestCase
{
    public function testNormalizesResources(): void
    {
        $this->assertSame([], ResourceIndicators::from(null)->toArray());
        $this->assertSame([], ResourceIndicators::from('')->toArray());
        $this->assertSame(['https://api.example.com/'], ResourceIndicators::from('https://api.example.com/')->toArray());
        $this->assertSame(
            ['https://api.example.com/'],
            ResourceIndicators::from(null, 'https://api.example.com/')->toArray(),
        );

        $this->assertSame(
            ['https://api.example.com/', 'http://localhost:8080/v1'],
            ResourceIndicators::from([
                'https://api.example.com/',
                'http://localhost:8080/v1',
                'https://api.example.com/',
            ])->toArray(),
        );
        $this->assertSame(
            ['https://api.example.com/', 'http://localhost:8080/v1'],
            ResourceIndicators::from([
                'https://api.example.com/',
                'http://localhost:8080/v1',
            ], 'https://api.example.com/')->toArray(),
        );
    }

    /**
     * @param string|array<int, mixed> $resources
     */
    #[DataProvider('invalidResourceProvider')]
    public function testRejectsInvalidResources(string|array $resources, string $message, ?string $audience = null): void
    {
        $this->assertSame('invalid_target', InvalidResourceException::ERROR_CODE);

        $this->expectException(InvalidResourceException::class);
        $this->expectExceptionMessage($message);

        ResourceIndicators::from($resources, $audience);
    }

    public function testComparesResourceSets(): void
    {
        $this->assertTrue(
            ResourceIndicators::from(['https://api.example.com/'])
                ->isSubsetOf(ResourceIndicators::from(['https://api.example.com/', 'https://files.example.com/'])),
        );
        $this->assertFalse(
            ResourceIndicators::from(['https://api.example.com/'])
                ->isSubsetOf(ResourceIndicators::from(['https://files.example.com/'])),
        );

        $this->assertTrue(
            ResourceIndicators::from(['https://api.example.com/', 'https://files.example.com/'])
                ->equals(ResourceIndicators::from(['https://files.example.com/', 'https://api.example.com/'])),
        );
    }

    public function testBuildsAudience(): void
    {
        $this->assertSame(
            ['https://cloud.appwrite.io/v1/project1'],
            ResourceIndicators::from(null)->audience('https://cloud.appwrite.io/v1/project1'),
        );

        $this->assertSame(
            ['https://mcp.example.com/'],
            ResourceIndicators::from([
                'https://mcp.example.com/',
            ])->audience('https://cloud.appwrite.io/v1/project1'),
        );

        $this->assertSame(
            ['https://cloud.appwrite.io/v1/project1'],
            ResourceIndicators::from([
                'https://cloud.appwrite.io/v1/project1',
            ])->audience('https://cloud.appwrite.io/v1/project1'),
        );
    }

    /**
     * @return \Iterator<string, array{resources: (array<int, mixed> | string), message: string}>
     */
    public static function invalidResourceProvider(): \Iterator
    {
        yield 'fragment' => [
            'resources' => 'https://api.example.com/#section',
            'message' => 'resource must be an absolute HTTP(S) URI with no fragment component.',
        ];
        yield 'relative URI' => [
            'resources' => '/relative',
            'message' => 'resource must be an absolute HTTP(S) URI with no fragment component.',
        ];
        yield 'urn URI' => [
            'resources' => 'urn:example:resource',
            'message' => 'resource must be an absolute HTTP(S) URI with no fragment component.',
        ];
        yield 'file URI' => [
            'resources' => 'file:///etc/passwd',
            'message' => 'resource must be an absolute HTTP(S) URI with no fragment component.',
        ];
        yield 'javascript URI' => [
            'resources' => 'javascript:alert(1)',
            'message' => 'resource must be an absolute HTTP(S) URI with no fragment component.',
        ];
        yield 'non-string' => [
            'resources' => ['https://api.example.com/', 42],
            'message' => 'resource must be a non-empty absolute URI.',
        ];
        yield 'invalid audience' => [
            'resources' => [],
            'message' => 'resource must be an absolute HTTP(S) URI with no fragment component.',
            'audience' => 'not-a-uri',
        ];
        yield 'audience outside resources' => [
            'resources' => ['https://api.example.com/'],
            'message' => 'audience must match one of the resource values when both parameters are provided.',
            'audience' => 'https://files.example.com/',
        ];
    }
}
