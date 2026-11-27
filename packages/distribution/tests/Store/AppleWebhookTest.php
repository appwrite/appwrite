<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Store;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Distribution\Credential;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Release;
use Utopia\Distribution\Store\Apple;
use Utopia\Distribution\Store\Apple\Webhook;
use Utopia\Distribution\Tests\Store\Apple\Frozen;
use Utopia\Distribution\Tests\Transport;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class AppleWebhookTest extends TestCase
{
    private const string KEY_ID = 'ABC123DEFG';

    private const string ISSUER_ID = '57246542-96fe-1a63-e053-0824d011072a';

    private const string APP_ID = '6450000001';

    private const string WEBHOOK_ID = '9c3e4f6a-2b1d-4e8f-a5c7-1d2e3f4a5b6c';

    private const string URL = 'https://cloud.example.io/v1/distribution/hooks/apple/6450000001';

    private const string SECRET = 'f3c9a1e07b2d4c58a6e1d0b9c7f24a13';

    private const int NOW = 1_795_000_000;

    private const string ENDPOINT = 'https://store.test/apple';

    private static string $privateKey = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        self::assertTrue(openssl_pkey_export($key, $private));
        self::assertIsString($private);

        self::$privateKey = $private;
    }

    public function testCreateWebhookPostsAllAttributesAndAppRelationship(): void
    {
        $transport = new Transport($this->reply('webhook', 201));

        $webhook = $this->store($transport)->createWebhook(self::APP_ID, self::URL, self::SECRET);

        $request = $this->only($transport);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/webhooks', (string) $request->getUri());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertStringStartsWith('Bearer ', $request->getHeaderLine('Authorization'));
        $this->assertSame([
            'data' => [
                'type' => 'webhooks',
                'attributes' => [
                    'enabled' => true,
                    'eventTypes' => Webhook::EVENTS,
                    'name' => 'Appwrite',
                    'secret' => self::SECRET,
                    'url' => self::URL,
                ],
                'relationships' => [
                    'app' => ['data' => ['type' => 'apps', 'id' => self::APP_ID]],
                ],
            ],
        ], $this->body($request));
        $this->assertSame(self::WEBHOOK_ID, $webhook->id);
        $this->assertSame('Appwrite', $webhook->name);
        $this->assertSame(self::URL, $webhook->url);
        $this->assertSame(Webhook::EVENTS, $webhook->events);
        $this->assertTrue($webhook->enabled);
    }

    public function testCreateWebhookSendsChosenEventsAndName(): void
    {
        $transport = new Transport($this->reply('webhook', 201));

        $this->store($transport)->createWebhook(self::APP_ID, self::URL, self::SECRET, [Webhook::EVENT_UPLOAD_STATE], 'Builds');

        $data = $this->body($this->only($transport))['data'] ?? null;
        $this->assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        $this->assertIsArray($attributes);
        $this->assertSame([Webhook::EVENT_UPLOAD_STATE], $attributes['eventTypes'] ?? null);
        $this->assertSame('Builds', $attributes['name'] ?? null);
    }

    public function testCreateWebhookRetriesUnauthorizedWithSameBody(): void
    {
        $transport = new Transport($this->reply('error-unauthorized', 401), $this->reply('webhook', 201));

        $this->store($transport)->createWebhook(self::APP_ID, self::URL, self::SECRET);

        [$first, $second] = $transport->requests();
        $this->assertSame('POST', $second->getMethod());
        $this->assertSame((string) $first->getBody(), (string) $second->getBody());
        $this->assertNotSame($first->getHeaderLine('Authorization'), $second->getHeaderLine('Authorization'));
    }

    public function testCreateWebhookForbiddenThrowsUnauthorized(): void
    {
        $transport = new Transport($this->reply('webhook-error-forbidden', 403));

        try {
            $this->store($transport)->createWebhook(self::APP_ID, self::URL, self::SECRET);
            $this->fail('createWebhook should throw');
        } catch (Unauthorized $unauthorized) {
            $this->assertFalse($unauthorized->isCredential());
            $this->assertSame('FORBIDDEN_ERROR', $unauthorized->getIssues()[0]->code);
        }

        $this->assertCount(1, $transport->requests());
    }

    public function testCreateWebhookWithoutDataObjectThrowsUnavailable(): void
    {
        $this->expectException(Unavailable::class);

        $this->store(new Transport($this->page(201, '{"links":{}}')))->createWebhook(self::APP_ID, self::URL, self::SECRET);
    }

    /**
     * @return iterable<string, array{string, string, string, list<string>, string}>
     */
    public static function incompleteWebhooks(): iterable
    {
        yield 'app id' => ['', self::URL, self::SECRET, Webhook::EVENTS, Webhook::NAME];
        yield 'url' => [self::APP_ID, ' ', self::SECRET, Webhook::EVENTS, Webhook::NAME];
        yield 'secret' => [self::APP_ID, self::URL, '', Webhook::EVENTS, Webhook::NAME];
        yield 'events' => [self::APP_ID, self::URL, self::SECRET, [], Webhook::NAME];
        yield 'name' => [self::APP_ID, self::URL, self::SECRET, Webhook::EVENTS, ''];
    }

    /**
     * @param list<string> $events
     */
    #[DataProvider('incompleteWebhooks')]
    public function testCreateWebhookRejectsMissingValueBeforeSending(string $appId, string $url, string $secret, array $events, string $name): void
    {
        $transport = new Transport();

        try {
            $this->store($transport)->createWebhook($appId, $url, $secret, $events, $name);
            $this->fail('createWebhook should throw');
        } catch (Invalid) {
            $this->assertSame([], $transport->requests());
        }
    }

    public function testListWebhooksReadsTheAppWebhooks(): void
    {
        $transport = new Transport($this->reply('webhooks'));

        $webhooks = $this->store($transport)->listWebhooks(self::APP_ID);

        $request = $this->only($transport);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/apps/' . self::APP_ID . '/webhooks?limit=200', (string) $request->getUri());
        $this->assertCount(2, $webhooks);
        $this->assertSame(self::WEBHOOK_ID, $webhooks[0]->id);
        $this->assertTrue($webhooks[0]->enabled);
        $this->assertSame('1f2e3d4c-5b6a-4978-8a1b-2c3d4e5f6a7b', $webhooks[1]->id);
        $this->assertSame('Legacy', $webhooks[1]->name);
        $this->assertSame('https://hooks.example.io/apple', $webhooks[1]->url);
        $this->assertSame([Webhook::EVENT_UPLOAD_STATE], $webhooks[1]->events);
        $this->assertFalse($webhooks[1]->enabled);
    }

    public function testListWebhooksWithoutWebhooksIsEmpty(): void
    {
        $this->assertSame([], $this->store(new Transport($this->reply('webhooks-empty')))->listWebhooks(self::APP_ID));
    }

    public function testListWebhooksEncodesTheAppId(): void
    {
        $transport = new Transport($this->reply('webhooks-empty'));

        $this->store($transport)->listWebhooks('64/50');

        $this->assertSame(self::ENDPOINT . '/v1/apps/64%2F50/webhooks?limit=200', (string) $this->only($transport)->getUri());
    }

    public function testDeleteWebhookAcceptsNoContent(): void
    {
        $transport = new Transport($this->page(204, ''));

        $this->store($transport)->deleteWebhook(self::WEBHOOK_ID);

        $request = $this->only($transport);
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/webhooks/' . self::WEBHOOK_ID, (string) $request->getUri());
        $this->assertSame('', (string) $request->getBody());
    }

    public function testDeleteWebhookOfUnknownWebhookThrowsNotFound(): void
    {
        $this->expectException(NotFound::class);

        $this->store(new Transport($this->reply('error-not-found', 404)))->deleteWebhook(self::WEBHOOK_ID);
    }

    public function testCreateWebhookPingPostsWebhookRelationship(): void
    {
        $transport = new Transport($this->reply('webhook-ping', 201));

        $this->store($transport)->createWebhookPing(self::WEBHOOK_ID);

        $request = $this->only($transport);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::ENDPOINT . '/v1/webhookPings', (string) $request->getUri());
        $this->assertSame([
            'data' => [
                'type' => 'webhookPings',
                'relationships' => [
                    'webhook' => ['data' => ['type' => 'webhooks', 'id' => self::WEBHOOK_ID]],
                ],
            ],
        ], $this->body($request));
    }

    public function testEmptyCreatedResponseIsAccepted(): void
    {
        $transport = new Transport($this->page(201, ''));

        $this->store($transport)->createWebhookPing(self::WEBHOOK_ID);

        $this->assertCount(1, $transport->requests());
    }

    public function testEmptyWebhookIdIsRejectedBeforeSending(): void
    {
        $transport = new Transport();

        try {
            $this->store($transport)->deleteWebhook('');
            $this->fail('deleteWebhook should throw');
        } catch (Invalid) {
            $this->assertSame([], $transport->requests());
        }
    }

    public function testReleaseToUnknownTrackThrowsInvalid(): void
    {
        $this->assertRejected(static function (Apple $store): void {
            $store->release(new Release('io.example.app', 'beta', '1.0.0', '1'), []);
        });
    }

    public function testStateOfUnknownTrackThrowsInvalid(): void
    {
        $this->assertRejected(static function (Apple $store): void {
            $store->state([], 'internal');
        });
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function phased(): iterable
    {
        foreach ([Release::TRACK_TESTFLIGHT, Release::TRACK_TESTFLIGHT_EXTERNAL, 'beta'] as $track) {
            yield "rollout on {$track}" => ['rollout', $track];
            yield "halt on {$track}" => ['halt', $track];
            yield "resume on {$track}" => ['resume', $track];
        }
    }

    #[DataProvider('phased')]
    public function testPhasedOperationOutsideProductionThrowsInvalid(string $operation, string $track): void
    {
        $this->assertRejected(static function (Apple $store) use ($operation, $track): void {
            if ($operation === 'rollout') {
                $store->rollout([], $track, 0.5);
            } elseif ($operation === 'halt') {
                $store->halt([], $track);
            } else {
                $store->resume([], $track);
            }
        });
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unwithdrawable(): iterable
    {
        yield 'testflight' => [['track' => Release::TRACK_TESTFLIGHT]];
        yield 'testflight external' => [['track' => Release::TRACK_TESTFLIGHT_EXTERNAL]];
        yield 'unknown track' => [['track' => 'beta']];
        yield 'no track' => [[]];
    }

    /**
     * @param array<string, mixed> $external
     */
    #[DataProvider('unwithdrawable')]
    public function testWithdrawOutsideProductionThrowsInvalid(array $external): void
    {
        $this->assertRejected(static function (Apple $store) use ($external): void {
            $store->withdraw($external);
        });
    }

    public function testWithdrawFromTestFlightExplainsBetaReview(): void
    {
        $this->expectException(Invalid::class);
        $this->expectExceptionMessage('A beta review cannot be withdrawn');

        $this->store(new Transport())->withdraw(['track' => Release::TRACK_TESTFLIGHT]);
    }

    /**
     * @param \Closure(Apple): void $operation
     */
    private function assertRejected(\Closure $operation): void
    {
        $transport = new Transport();

        try {
            $operation($this->store($transport));
            $this->fail('operation should throw');
        } catch (Invalid) {
            $this->assertSame([], $transport->requests());
        }
    }

    private function store(Transport $transport): Frozen
    {
        return new Frozen(new Credential\Apple(self::KEY_ID, self::ISSUER_ID, self::$privateKey), $transport, self::NOW, self::ENDPOINT);
    }

    private function reply(string $fixture, int $status = 200): ResponseInterface
    {
        return Transport::fixture("Apple/{$fixture}", $status, ['X-Rate-Limit' => 'user-hour-lim:3500;user-hour-rem:3499;']);
    }

    private function page(int $status, string $body): ResponseInterface
    {
        return new Response($status, body: new Stream\Factory()->createStream($body));
    }

    private function only(Transport $transport): RequestInterface
    {
        $requests = $transport->requests();
        $this->assertCount(1, $requests);

        return $requests[0];
    }

    /**
     * @return array<mixed>
     */
    private function body(RequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);

        return $body;
    }
}
