<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\Push;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Push\FCM;
use Utopia\Messaging\Messages\Push;

final class FCMTest extends TestCase
{
    public function testChannelIdIsSentOnTheAndroidNotification(): void
    {
        $stub = new FCMStub($this->serviceAccount());

        $stub->send(new Push(
            to: ['token'],
            title: 'Title',
            body: 'Body',
            channelId: 'reminders',
        ));

        $this->assertSame('reminders', $stub->capturedBodies[0]['message']['android']['notification']['channel_id']);
    }

    public function testWithoutChannelIdOmitsTheField(): void
    {
        $stub = new FCMStub($this->serviceAccount());

        $stub->send(new Push(
            to: ['token'],
            title: 'Title',
            body: 'Body',
            tag: 'inbox',
        ));

        $this->assertArrayNotHasKey('channel_id', $stub->capturedBodies[0]['message']['android']['notification']);
    }

    /**
     * The adapter signs a real RS256 assertion before it builds any payload,
     * so the credentials carry a freshly generated key rather than a literal.
     */
    private function serviceAccount(): string
    {
        $key = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        \openssl_pkey_export($key, $privateKey);

        return (string) \json_encode([
            'client_email' => 'sender@example.test',
            'private_key' => $privateKey,
            'project_id' => 'project',
        ]);
    }
}

class FCMStub extends FCM
{
    /**
     * @var array<array<string, mixed>>
     */
    public array $capturedBodies = [];

    /**
     * @param  array<string>  $headers
     * @param  array<string, mixed>|null  $body
     * @return array{url: string, statusCode: int, response: array<string, mixed>|string|null, headers: array<string, string>, error: string|null, errorCode: int}
     */
    #[\Override]
    protected function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $body = null,
        int $timeout = 30,
        int $connectTimeout = 10,
    ): array {
        return ['url' => $url, 'statusCode' => 200, 'response' => ['access_token' => 'token'], 'headers' => [], 'error' => '', 'errorCode' => 0];
    }

    /**
     * @param  array<string>  $urls
     * @param  array<string>  $headers
     * @param  array<array<string, mixed>>  $bodies
     * @return array<array{index: int, url: string, statusCode: int, response: array<string, mixed>|string|null, headers: array<string, string>, error: string, errorCode: int}>
     */
    #[\Override]
    protected function requestMulti(
        string $method,
        array $urls,
        array $headers = [],
        array $bodies = [],
        int $timeout = 30,
        int $connectTimeout = 10,
    ): array {
        $this->capturedBodies = $bodies;

        $results = [];
        foreach ($bodies as $index => $body) {
            $results[] = ['index' => $index, 'url' => $urls[0], 'statusCode' => 200, 'response' => null, 'headers' => [], 'error' => '', 'errorCode' => 0];
        }

        return $results;
    }
}
