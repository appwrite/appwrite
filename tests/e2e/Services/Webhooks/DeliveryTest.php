<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Webhooks;

use Appwrite\Event\Publisher\Notification;
use Appwrite\Event\Publisher\Usage;
use Appwrite\Platform\Workers\Webhooks;
use Appwrite\Tests\Queue\InMemoryConnection;
use Exception;
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
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Queue;

/**
 * Exercises queued messages through the real worker and HTTP transport.
 * Only queue storage and the unused platform database are in memory.
 */
final class DeliveryTest extends TestCase
{
    public function testProductionDeliveryOnlyTrustsConfiguredOrigins(): void
    {
        $environment = getenv('_APP_ENV');
        $origins = getenv('_APP_WEBHOOK_TRUSTED_ORIGINS');
        $hooks = Runtime::getHookFlags();
        putenv('_APP_ENV=production');
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
                $worker = new Webhooks();
                $url = $origin . '/events?delivery=one';
                $payload = ['value' => 'delivered'];
                $project = new Document([
                    '$id' => 'delivery-project',
                    '$sequence' => 1,
                    'webhooks' => [new Document([
                        '$id' => 'delivery-webhook',
                        '$sequence' => 1,
                        'enabled' => true,
                        'events' => ['users.create'],
                        'url' => $url,
                        'signatureKey' => 'delivery-signature',
                    ])],
                ]);

                try {
                    // Test for FAILURE: no setting, wrong origins and malformed entries
                    // must leave the receiver untouched, not just raise an exception.
                    foreach ([
                        '',
                        ' ',
                        '*',
                        'https://127.0.0.1:' . $server->port,
                        'http://127.0.0.1:' . ($server->port === 65535 ? 65534 : $server->port + 1),
                        'http://localhost:' . $server->port,
                        'http://127.0.0.10:' . $server->port,
                        'http://*.0.0.1:' . $server->port,
                        $origin . '/events',
                        $origin . '?trusted=true',
                        $origin . '#trusted',
                        'http://user:password@127.0.0.1:' . $server->port,
                        'http://127.0.0.1:invalid',
                    ] as $untrusted) {
                        putenv('_APP_WEBHOOK_TRUSTED_ORIGINS=' . $untrusted);
                        $error = $this->deliver($worker, $broker, $queue, $database, $project, $payload);
                        $this->assertInstanceOf(\Exception::class, $error, 'Expected rejection with origins: ' . $untrusted);
                        $this->assertTrue($requests->isEmpty(), 'Rejected target must receive no request');
                    }

                    // Test for SUCCESS: a list with whitespace and a trailing slash
                    // trusts this origin, including paths and query strings on it.
                    putenv('_APP_WEBHOOK_TRUSTED_ORIGINS=invalid, ' . strtoupper($origin) . '/ , https://other.test');
                    $this->assertNull($this->deliver($worker, $broker, $queue, $database, $project, $payload));
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
                    $this->assertInstanceOf(\Exception::class, $this->deliver($worker, $broker, $queue, $database, $project, $payload));
                    $this->assertTrue($requests->isEmpty());
                    $project->getAttribute('webhooks')[0]->setAttribute('url', $url);

                    // Revoking trust must stop delivery to the same running receiver.
                    putenv('_APP_WEBHOOK_TRUSTED_ORIGINS');
                    $this->assertInstanceOf(\Exception::class, $this->deliver($worker, $broker, $queue, $database, $project, $payload));
                    $this->assertTrue($requests->isEmpty());

                    // Trust does not enable redirect following.
                    putenv('_APP_WEBHOOK_TRUSTED_ORIGINS=' . $origin);
                    $project->getAttribute('webhooks')[0]->setAttribute('url', $origin . '/redirect');
                    $this->assertNull($this->deliver($worker, $broker, $queue, $database, $project, $payload));
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
