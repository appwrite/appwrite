<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Exception;
use Appwrite\Auth\OAuth2\Github;
use Appwrite\Extend\Exception as AppwriteException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GithubTest extends TestCase
{
    public function testAccessToken(): void
    {
        $github = $this->createGithub(\json_encode([
            'access_token' => 'access-token',
            'scope' => 'user:email',
            'token_type' => 'bearer',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('access-token', $github->getAccessToken('authorization-code'));
    }

    public function testProviderError(): void
    {
        $github = $this->createGithub(\json_encode([
            'error' => 'bad_verification_code',
            'error_description' => 'The code passed is incorrect or expired.',
        ], JSON_THROW_ON_ERROR), 'expired-code');

        try {
            $github->getAccessToken('expired-code');
            $this->fail('Expected the GitHub OAuth2 provider error to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('bad_verification_code', $exception->getError());
            $this->assertSame('The code passed is incorrect or expired.', $exception->getErrorDescription());
        }
    }

    public function testFormEncodedProviderError(): void
    {
        $github = $this->createGithub(
            'error=bad_verification_code&error_description=The+code+passed+is+incorrect+or+expired.',
            'expired-code',
        );

        try {
            $github->getAccessToken('expired-code');
            $this->fail('Expected the form-encoded GitHub OAuth2 provider error to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('bad_verification_code', $exception->getError());
            $this->assertSame('The code passed is incorrect or expired.', $exception->getErrorDescription());
        }
    }

    public function testProviderErrorWithInvalidUtf8(): void
    {
        $github = $this->createGithub(
            'error=bad_verification_code&error_description=Invalid+byte%3A+%FF',
            'expired-code',
        );

        try {
            $github->getAccessToken('expired-code');
            $this->fail('Expected the GitHub OAuth2 provider error with invalid UTF-8 to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('bad_verification_code', $exception->getError());
            $this->assertSame('Invalid byte: �', $exception->getErrorDescription());
        }
    }

    public function testMissingAccessToken(): void
    {
        $github = $this->createGithub('{}');

        try {
            $github->getAccessToken('authorization-code');
            $this->fail('Expected a missing access token error to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('access_token_missing', $exception->getError());
            $this->assertSame('GitHub did not return an access token.', $exception->getErrorDescription());
        }
    }

    public function testProviderFailure(): void
    {
        $previous = new Exception(\json_encode([
            'error' => 'bad_verification_code',
            'error_description' => 'The code passed is incorrect or expired.',
        ], JSON_THROW_ON_ERROR), 400);

        $exception = new AppwriteException(
            AppwriteException::USER_OAUTH2_PROVIDER_FAILURE,
            previous: $previous,
            params: ['GitHub', $previous->getError()],
        );

        $this->assertSame(AppwriteException::USER_OAUTH2_PROVIDER_FAILURE, $exception->getType());
        $this->assertSame(424, $exception->getCode());
        $this->assertSame(
            'GitHub couldn\'t complete sign-in (bad_verification_code). Please try again.',
            $exception->getMessage(),
        );
        $this->assertSame($previous, $exception->getPrevious());
    }

    private function createGithub(string $response, string $code = 'authorization-code'): Github&MockObject
    {
        $github = $this->getMockBuilder(Github::class)
            ->setConstructorArgs(['client-id', 'client-secret', 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $github
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://github.com/login/oauth/access_token',
                ['Accept: application/json'],
                $this->callback(function (mixed $payload) use ($code): bool {
                    if (!\is_string($payload)) {
                        return false;
                    }

                    \parse_str($payload, $params);

                    return $params === [
                        'client_id' => 'client-id',
                        'redirect_uri' => 'https://example.com/callback',
                        'client_secret' => 'client-secret',
                        'code' => $code,
                    ];
                }),
            )
            ->willReturn($response);

        return $github;
    }
}
