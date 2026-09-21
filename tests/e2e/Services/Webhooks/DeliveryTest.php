<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Webhooks;

use Appwrite\Event\Publisher\Notification;
use Appwrite\Event\Publisher\Usage;
use Appwrite\Platform\Workers\Webhooks;
use Appwrite\Tests\Queue\InMemoryConnection;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Runtime;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;

/**
 * Exercises queued messages through the real worker and HTTP transport.
 * Queue storage and the platform database are in memory.
 */
final class DeliveryTest extends TestCase
{
    public static function environments(): \Iterator
    {
        yield ['development'];
        yield ['production'];
    }

    #[DataProvider('environments')]
    public function testDeliveryOnlyTrustsConfiguredOrigins(string $mode): void
    {
        $environment = getenv('_APP_ENV');
        $origins = getenv('_APP_WEBHOOK_TRUSTED_ORIGINS');
        $hooks = Runtime::getHookFlags();
        putenv('_APP_ENV=' . $mode);
        Runtime::enableCoroutine(SWOOLE_HOOK_NATIVE_CURL);

        try {
            $failure = null;
            Coroutine\run(function () use (&$failure): void {
                $requests = new Channel(16);
                $server = new Server('127.0.0.1', 0);
                $origin = 'http://127.0.0.1:' . $server->port;
                $server->handle('/', function (Request $request, Response $response) use ($requests, $origin): void {
                    $requests->push([
                        'method' => $request->server['request_method'],
                        'path' => $request->server['request_uri'],
                        'headers' => $request->header,
                        'body' => $request->rawContent(),
                    ]);
                    if ($request->server['request_uri'] === '/redirect') {
                        $response->status(302);
                        $response->header('Location', $origin . '/redirect-target');
                    }
                    $response->end('received');
                });
                Coroutine::create($server->start(...));

                $connection = new InMemoryConnection();
                $broker = new Redis($connection, $connection);
                $queue = new Queue('v1-webhooks');
                $database = new Database(new Memory(), new Cache(new NoCache()));
                $authorization = new Authorization();
                $authorization->disable();
                $database->setAuthorization($authorization)->setDatabase('delivery')->setNamespace('webhooks');
                $database->create();
                $database->createCollection('webhooks');
                $database->createAttribute('webhooks', 'attempts', Database::VAR_INTEGER, 0, false, 0);
                $database->createCollection('memberships');
                $database->createAttribute('memberships', 'teamInternalId', Database::VAR_STRING, 255, false);
                $database->disableValidation();
                $url = $origin . '/events?delivery=one';
                $payload = ['value' => 'delivered'];
                $project = new Document([
                    '$id' => 'delivery-project',
                    '$sequence' => 1,
                    'teamInternalId' => 'team',
                    'webhooks' => [new Document([
                        '$id' => 'delivery-webhook',
                        '$sequence' => 1,
                        'enabled' => true,
                        'attempts' => 0,
                        'events' => ['users.create'],
                        'url' => $url,
                        'signatureKey' => 'delivery-signature',
                    ])],
                ]);

                $database->createDocument('webhooks', $project->getAttribute('webhooks')[0]);

                try {
                    // An unconfigured private receiver gets no request, even in development.
                    putenv('_APP_WEBHOOK_TRUSTED_ORIGINS=');
                    $this->assertInstanceOf(Exception::class, $this->deliver(new Webhooks(), $broker, $queue, $database, $project, $payload));
                    $this->assertTrue($requests->isEmpty());
                    $this->assertSame(1, $database->getDocument('webhooks', 'delivery-webhook')->getAttribute('attempts'));
                    $project->setAttribute('webhooks', [$database->getDocument('webhooks', 'delivery-webhook')]);

                    // Test for SUCCESS: a list with whitespace and a trailing slash
                    // trusts this origin, including paths and query strings on it.
                    putenv('_APP_WEBHOOK_TRUSTED_ORIGINS=invalid, ' . strtoupper($origin) . '/ , https://other.test');
                    $this->assertNull($this->deliver(new Webhooks(), $broker, $queue, $database, $project, $payload));
                    $this->assertSame(0, $database->getDocument('webhooks', 'delivery-webhook')->getAttribute('attempts'));
                    $this->assertSame(1, $requests->length());
                    $request = $requests->pop(1);
                    $this->assertIsArray($request);
                    $this->assertSame('POST', $request['method']);
                    $this->assertSame('/events', $request['path']);
                    $this->assertSame($payload, json_decode($request['body'], true));
                    $this->assertSame('delivery-webhook', $request['headers']['x-appwrite-webhook-id']);
                    $this->assertSame(
                        base64_encode(hash_hmac('sha1', $url . $request['body'], 'delivery-signature', true)),
                        $request['headers']['x-appwrite-webhook-signature'],
                    );

                    // Trusting one private origin must not authorize another.
                    $project->getAttribute('webhooks')[0]->setAttribute('url', 'http://localhost:' . $server->port . '/events');
                    $this->assertInstanceOf(\Exception::class, $this->deliver(new Webhooks(), $broker, $queue, $database, $project, $payload));
                    $this->assertTrue($requests->isEmpty());
                    $project->getAttribute('webhooks')[0]->setAttribute('url', $url);

                    // Revocation stops delivery and repeated refusals eventually pause it.
                    putenv('_APP_WEBHOOK_TRUSTED_ORIGINS');
                    for ($attempt = 2; $attempt <= 10; $attempt++) {
                        $project->setAttribute('webhooks', [$database->getDocument('webhooks', 'delivery-webhook')]);
                        $this->assertInstanceOf(Exception::class, $this->deliver(new Webhooks(), $broker, $queue, $database, $project, $payload));
                    }
                    $paused = $database->getDocument('webhooks', 'delivery-webhook');
                    $this->assertSame(10, $paused->getAttribute('attempts'));
                    $this->assertFalse($paused->getAttribute('enabled'));
                    $project->setAttribute('webhooks', [$paused]);
                    $this->assertNull($this->deliver(new Webhooks(), $broker, $queue, $database, $project, $payload));
                    $this->assertTrue($requests->isEmpty());

                    // Trust does not enable redirect following.
                    putenv('_APP_WEBHOOK_TRUSTED_ORIGINS=' . $origin);
                    $project->getAttribute('webhooks')[0]->setAttribute('enabled', true)->setAttribute('url', $origin . '/redirect');
                    $this->assertNull($this->deliver(new Webhooks(), $broker, $queue, $database, $project, $payload));
                    $redirect = $requests->pop(1);
                    $this->assertIsArray($redirect);
                    $this->assertSame('/redirect', $redirect['path']);
                    $this->assertTrue($requests->isEmpty());
                } catch (\Throwable $error) {
                    $failure = $error;
                } finally {
                    $server->shutdown();
                }
            });
            if ($failure !== null) {
                throw $failure;
            }
        } finally {
            Runtime::setHookFlags($hooks);
            putenv($environment === false ? '_APP_ENV' : '_APP_ENV=' . $environment);
            putenv($origins === false ? '_APP_WEBHOOK_TRUSTED_ORIGINS' : '_APP_WEBHOOK_TRUSTED_ORIGINS=' . $origins);
        }
    }

    private function deliver(Webhooks $worker, Redis $broker, Queue $queue, Database $database, Document $project, array $payload): ?Exception
    {
        $broker->publish($queue, ['events' => ['users.create'], 'payload' => $payload]);
        $message = $broker->receive($queue, 1);
        $this->assertInstanceOf(\Utopia\Queue\Message::class, $message);

        try {
            $worker->action(
                $message,
                $project,
                $database,
                new Notification($broker, new Queue('v1-notifications')),
                new Usage($broker, new Queue('v1-usage')),
                [],
                [],
            );
        } catch (Exception $error) {
            return $error;
        }

        return null;
    }
}
