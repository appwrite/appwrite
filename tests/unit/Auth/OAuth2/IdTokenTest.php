<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Github;
use PHPUnit\Framework\TestCase;

final class IdTokenTest extends TestCase
{
    /**
     * OpenID Connect providers return an id_token alongside the access token,
     * and the base class surfaces it verbatim.
     */
    public function testIdTokenIsReadFromTokenResponse(): void
    {
        $provider = $this->createGithub(\json_encode([
            'access_token' => 'access-token',
            'id_token' => 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxMjM0NTYifQ.signature',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(
            'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxMjM0NTYifQ.signature',
            $provider->getIdToken('authorization-code'),
        );
    }

    /**
     * Plain OAuth2 providers issue no id_token; the accessor must degrade to
     * an empty string rather than raise.
     */
    public function testMissingIdTokenYieldsEmptyString(): void
    {
        $provider = $this->createGithub(\json_encode([
            'access_token' => 'access-token',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('', $provider->getIdToken('authorization-code'));
    }

    private function createGithub(string $response): Github
    {
        $github = $this->getMockBuilder(Github::class)
            ->setConstructorArgs(['client-id', 'client-secret', 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $github->expects($this->once())->method('request')->willReturn($response);

        return $github;
    }
}
