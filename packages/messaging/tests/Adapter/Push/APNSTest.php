<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\Push;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Push\APNS;
use Utopia\Messaging\Messages\Push;
use Utopia\Messaging\Priority;

final class APNSTest extends TestCase
{
    /**
     * A wake signal (no title/body, data + content-available) must render without an alert, so iOS
     * delivers it silently and the app can reconnect and replay rather than show a notification.
     */
    public function testSilentWakeHasNoAlert(): void
    {
        $stub = new APNSStub($this->authKey(), 'keyId', 'teamId', 'com.example.app');

        $stub->send(new Push(
            to: ['token'],
            data: ['type' => 'wake', 'messageId' => 'msg1'],
            contentAvailable: true,
            priority: Priority::HIGH,
        ));

        $body = $stub->capturedBodies[0];
        $aps = $body['aps'];

        $this->assertArrayNotHasKey('alert', $aps);
        $this->assertArrayNotHasKey('data', $aps);
        // Custom data sits at the payload root, beside `aps`, so iOS reads it as userInfo["data"].
        $this->assertSame(['type' => 'wake', 'messageId' => 'msg1'], $body['data']);
        $this->assertSame(1, $aps['content-available']);

        // A silent wake must be a background push (type background, priority 5) or iOS drops it.
        $this->assertContains('apns-push-type: background', $stub->capturedHeaders);
        $this->assertContains('apns-priority: 5', $stub->capturedHeaders);
    }

    /**
     * A content-available push that also carries a badge is not background-eligible: APNs rejects a
     * background push with a badge, so it must stay an alert push.
     */
    public function testContentAvailableWithBadgeStaysAlert(): void
    {
        $stub = new APNSStub($this->authKey(), 'keyId', 'teamId', 'com.example.app');

        $stub->send(new Push(
            to: ['token'],
            data: ['k' => 'v'],
            badge: 3,
            contentAvailable: true,
        ));

        $this->assertContains('apns-push-type: alert', $stub->capturedHeaders);
        $this->assertNotContains('apns-push-type: background', $stub->capturedHeaders);
    }

    /**
     * A data-only push still carries an `aps` dictionary, which APNs requires on every payload.
     */
    public function testDataOnlyKeepsApsDictionary(): void
    {
        $stub = new APNSStub($this->authKey(), 'keyId', 'teamId', 'com.example.app');

        $stub->send(new Push(
            to: ['token'],
            data: ['k' => 'v'],
        ));

        $this->assertArrayHasKey('aps', $stub->capturedBodies[0]);
        $this->assertSame(['k' => 'v'], $stub->capturedBodies[0]['data']);
    }

    /**
     * A regular notification keeps the alert push type and sends its priority as an HTTP header.
     */
    public function testAlertUsesAlertPushType(): void
    {
        $stub = new APNSStub($this->authKey(), 'keyId', 'teamId', 'com.example.app');

        $stub->send(new Push(
            to: ['token'],
            title: 'Title',
            body: 'Body',
            priority: Priority::HIGH,
        ));

        $this->assertSame('Title', $stub->capturedBodies[0]['aps']['alert']['title']);
        $this->assertContains('apns-push-type: alert', $stub->capturedHeaders);
        $this->assertContains('apns-priority: 10', $stub->capturedHeaders);
    }

    /**
     * The adapter signs a real ES256 assertion before it builds any payload, so the credentials carry a
     * freshly generated EC key rather than a literal.
     */
    private function authKey(): string
    {
        $key = \openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \openssl_pkey_export($key, $privateKey);

        return (string) $privateKey;
    }
}

class APNSStub extends APNS
{
    /**
     * @var array<array<string, mixed>>
     */
    public array $capturedBodies = [];

    /**
     * @var array<string>
     */
    public array $capturedHeaders = [];

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
        $this->capturedHeaders = $headers;

        $results = [];
        foreach ($urls as $index => $url) {
            $results[] = ['index' => $index, 'url' => $url, 'statusCode' => 200, 'response' => null, 'headers' => [], 'error' => '', 'errorCode' => 0];
        }

        return $results;
    }
}
