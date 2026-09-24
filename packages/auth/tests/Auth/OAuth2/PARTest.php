<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\InvalidRequestUriException;
use Utopia\Auth\OAuth2\PAR;

final class PARTest extends TestCase
{
    private const string PREFIX = 'urn:appwrite:oauth2:request:';

    public function testBuildsAndParsesRequestUri(): void
    {
        $par = PAR::fromId(self::PREFIX, 'grant123');

        $this->assertSame('grant123', $par->id());
        $this->assertSame('urn:appwrite:oauth2:request:grant123', $par->requestUri());

        $parsed = PAR::fromRequestUri(self::PREFIX, $par->requestUri());

        $this->assertSame('grant123', $parsed->id());
        $this->assertSame('urn:appwrite:oauth2:request:grant123', $parsed->requestUri());
    }

    /**
     * @param non-empty-string $requestUri
     */
    #[DataProvider('invalidRequestUriProvider')]
    public function testRejectsInvalidRequestUri(string $prefix, string $requestUri, string $message): void
    {
        $this->assertSame('invalid_request', InvalidRequestUriException::ERROR_CODE);

        $this->expectException(InvalidRequestUriException::class);
        $this->expectExceptionMessage($message);

        PAR::fromRequestUri($prefix, $requestUri);
    }

    #[DataProvider('invalidRequestUriPartsProvider')]
    public function testRejectsEmptyRequestUriParts(string $prefix, string $id): void
    {
        $this->expectException(InvalidRequestUriException::class);
        $this->expectExceptionMessage('request_uri prefix and id must be non-empty strings.');

        PAR::fromId($prefix, $id);
    }

    /**
     * @return \Iterator<string, array{prefix: string, requestUri: non-empty-string, message: string}>
     */
    public static function invalidRequestUriProvider(): \Iterator
    {
        yield 'empty prefix' => [
            'prefix' => '',
            'requestUri' => 'urn:appwrite:oauth2:request:grant123',
            'message' => 'Invalid request_uri.',
        ];
        yield 'wrong prefix' => [
            'prefix' => self::PREFIX,
            'requestUri' => 'urn:example:oauth2:request:grant123',
            'message' => 'Invalid request_uri.',
        ];
        yield 'empty id' => [
            'prefix' => self::PREFIX,
            'requestUri' => self::PREFIX,
            'message' => 'Invalid request_uri.',
        ];
    }

    /**
     * @return \Iterator<string, array{prefix: string, id: string}>
     */
    public static function invalidRequestUriPartsProvider(): \Iterator
    {
        yield 'empty prefix' => [
            'prefix' => '',
            'id' => 'grant123',
        ];
        yield 'empty id' => [
            'prefix' => self::PREFIX,
            'id' => '',
        ];
    }
}
