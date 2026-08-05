<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\Exception;
use Appwrite\Auth\OAuth2\HuggingFace;
use Appwrite\Extend\Exception as AppwriteException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HuggingFaceTest extends TestCase
{
    public function testAccessToken(): void
    {
        $huggingface = $this->createHuggingFace(\json_encode([
            'access_token' => 'access-token',
            'token_type' => 'bearer',
            'scope' => 'read',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('access-token', $huggingface->getAccessToken('authorization-code'));
    }

    public function testProviderError(): void
    {
        $huggingface = $this->createHuggingFace(\json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'The authorization code is invalid or expired.',
        ], JSON_THROW_ON_ERROR), 'expired-code');

        try {
            $huggingface->getAccessToken('expired-code');
            $this->fail('Expected the Hugging Face OAuth2 provider error to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('invalid_grant', $exception->getError());
            $this->assertSame('The authorization code is invalid or expired.', $exception->getErrorDescription());
        }
    }

    public function testFormEncodedProviderError(): void
    {
        $huggingface = $this->createHuggingFace(
            'error=invalid_grant&error_description=The+authorization+code+is+invalid+or+expired.',
            'expired-code',
        );

        try {
            $huggingface->getAccessToken('expired-code');
            $this->fail('Expected the form-encoded Hugging Face OAuth2 provider error to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('invalid_grant', $exception->getError());
            $this->assertSame('The authorization code is invalid or expired.', $exception->getErrorDescription());
        }
    }

    public function testProviderErrorWithInvalidUtf8(): void
    {
        $huggingface = $this->createHuggingFace(
            'error=invalid_grant&error_description=Invalid+byte%3A+%FF',
            'expired-code',
        );

        try {
            $huggingface->getAccessToken('expired-code');
            $this->fail('Expected the Hugging Face OAuth2 provider error with invalid UTF-8 to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('invalid_grant', $exception->getError());
            $this->assertSame('Invalid byte: �', $exception->getErrorDescription());
        }
    }

    public function testMissingAccessToken(): void
    {
        $huggingface = $this->createHuggingFace('{}');

        try {
            $huggingface->getAccessToken('authorization-code');
            $this->fail('Expected a missing access token error to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(AppwriteException::USER_OAUTH2_BAD_REQUEST, $exception->getType());
            $this->assertSame('access_token_missing', $exception->getError());
            $this->assertSame('Hugging Face did not return an access token.', $exception->getErrorDescription());
        }
    }

    public function testProviderFailure(): void
    {
        $previous = new Exception(\json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'The authorization code is invalid or expired.',
        ], JSON_THROW_ON_ERROR), 400);

        $exception = new AppwriteException(
            AppwriteException::USER_OAUTH2_PROVIDER_FAILURE,
            previous: $previous,
            params: ['Hugging Face', $previous->getError()],
        );

        $this->assertSame(AppwriteException::USER_OAUTH2_PROVIDER_FAILURE, $exception->getType());
        $this->assertSame(424, $exception->getCode());
        $this->assertSame(
            'Hugging Face couldn\'t complete sign-in (invalid_grant). Please try again.',
            $exception->getMessage(),
        );
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testPlainTextProviderFailure(): void
    {
        $previous = new Exception('Invalid secret');
        $providerError = $previous->getError() ?: $previous->getMessage();

        $exception = new AppwriteException(
            AppwriteException::USER_OAUTH2_PROVIDER_FAILURE,
            previous: $previous,
            params: ['Hugging Face', $providerError],
        );

        $this->assertSame(
            'Hugging Face couldn\'t complete sign-in (Invalid secret). Please try again.',
            $exception->getMessage(),
        );
        $this->assertSame($previous, $exception->getPrevious());
    }

    private function createHuggingFace(string $response, string $code = 'authorization-code'): HuggingFace&MockObject
    {
        $huggingface = $this->getMockBuilder(HuggingFace::class)
            ->setConstructorArgs(['client-id', 'client-secret', 'https://example.com/callback'])
            ->onlyMethods(['request'])
            ->getMock();

        $huggingface
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://huggingface.co/oauth/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                $this->callback(function (mixed $payload) use ($code): bool {
                    if (!\is_string($payload)) {
                        return false;
                    }

                    \parse_str($payload, $params);

                    $this->assertSame([
                        'grant_type' => 'authorization_code',
                        'code' => $code,
                        'redirect_uri' => 'https://example.com/callback',
                        'client_id' => 'client-id',
                        'client_secret' => 'client-secret',
                    ], $params);

                    return true;
                }),
            )
            ->willReturn($response);

        return $huggingface;
    }
}