<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\E2E\Support;

use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Auth\Authenticator;
use Utopia\SMTP\Auth\Plain;
use Utopia\SMTP\Client;
use Utopia\SMTP\Encryption;
use Utopia\SMTP\Tls;
use Utopia\SMTP\Transport\Native;
use Utopia\SMTP\Transport\Swoole as SwooleTransport;
use Utopia\SMTP\Verification;

/**
 * The two servers the end-to-end tier runs against, and the read-back API that
 * says what arrived.
 *
 * Both present the self-signed pair in tests/fixtures/certs. No trust store
 * knows the issuer, but the certificate does name the host we dial, so that
 * half is still checked.
 */
abstract class Server extends TestCase
{
    protected const string HOST = '127.0.0.1';

    /** Upgrades through STARTTLS, and refuses anything outside example.test. */
    protected const int PORT = 11026;

    protected const string API = 'http://127.0.0.1:18026';

    /** TLS from the first byte, and no more than two recipients a message. */
    protected const int IMPLICIT_PORT = 11027;

    protected const string IMPLICIT_API = 'http://127.0.0.1:18027';

    protected function setUp(): void
    {
        foreach ([self::API, self::IMPLICIT_API] as $api) {
            // Answers with an empty body, so it is not read as an object.
            $this->get('DELETE', '/api/v1/messages', $api);
        }
    }

    /**
     * @param  list<Authenticator>  $authenticators
     */
    protected function client(
        Encryption $encryption = Encryption::StartTls,
        array $authenticators = [],
        int $port = self::PORT,
    ): Client {
        return new Client(
            new Native(self::HOST, $port, new Tls(verify: Verification::SelfSigned)),
            'tests.example.test',
            $authenticators === [] ? [new Plain('jane', 'secret')] : $authenticators,
            $encryption,
        );
    }

    /**
     * The same client over the coroutine transport, which is the only way to
     * reach Swoole's own handshake rather than the stream one.
     */
    protected function coroutineClient(Encryption $encryption = Encryption::StartTls, int $port = self::PORT): Client
    {
        return new Client(
            // The coroutine transport matches DNS names only, and the servers
            // answer on an address, so it is told which name to expect.
            new SwooleTransport(self::HOST, $port, new Tls(verify: Verification::SelfSigned, peerName: 'localhost')),
            'tests.example.test',
            [new Plain('jane', 'secret')],
            $encryption,
        );
    }

    /**
     * Run a body inside a scheduler, carrying any failure back out. Left to
     * escape the coroutine, an assertion becomes a fatal instead of a result.
     */
    protected function coroutine(callable $body): void
    {
        $failure = null;

        \Swoole\Coroutine\run(static function () use ($body, &$failure): void {
            try {
                $body();
            } catch (\Throwable $thrown) {
                $failure = $thrown;
            }
        });

        if ($failure instanceof \Throwable) {
            throw $failure;
        }
    }

    protected function get(string $method, string $path, string $api = self::API): string
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true]]);
        $body = @file_get_contents($api . $path, false, $context);

        if ($body === false) {
            $this->fail("Mailpit did not answer {$method} {$path}");
        }

        return $body;
    }

    /**
     * @return array<mixed>
     */
    protected function api(string $method, string $path, string $api = self::API): array
    {
        $decoded = json_decode($this->get($method, $path, $api), true);

        $this->assertIsArray($decoded, "Mailpit answered {$path} with something other than an object");

        return $decoded;
    }

    /**
     * Everything the server kept, newest first.
     *
     * @return array<mixed>
     */
    protected function messages(string $api = self::API): array
    {
        return $this->listOf($this->api('GET', '/api/v1/messages', $api), 'messages');
    }

    /**
     * The one message the server kept.
     *
     * @return array<mixed>
     */
    protected function delivered(string $api = self::API): array
    {
        return $this->api('GET', '/api/v1/message/' . $this->id($api), $api);
    }

    /**
     * The bytes as they arrived, before the server interprets them.
     */
    protected function source(string $api = self::API): string
    {
        return $this->get('GET', '/api/v1/message/' . $this->id($api) . '/raw', $api);
    }

    /**
     * One decoded body part, by the identifier the message listing gave it.
     */
    protected function part(string $partId, string $api = self::API): string
    {
        return $this->get('GET', '/api/v1/message/' . $this->id($api) . "/part/{$partId}", $api);
    }

    /**
     * A field the API promises is a string.
     *
     * @param  array<mixed>  $message
     */
    protected function field(array $message, string $key): string
    {
        $this->assertArrayHasKey($key, $message);
        $this->assertIsString($message[$key]);

        return $message[$key];
    }

    /**
     * A field the API promises is a number.
     *
     * @param  array<mixed>  $message
     */
    protected function number(array $message, string $key): int
    {
        $this->assertArrayHasKey($key, $message);
        $this->assertIsInt($message[$key]);

        return $message[$key];
    }

    /**
     * A field the API promises is a list.
     *
     * @param  array<mixed>  $message
     * @return array<mixed>
     */
    protected function listOf(array $message, string $key): array
    {
        $this->assertArrayHasKey($key, $message);
        $this->assertIsArray($message[$key]);

        return $message[$key];
    }

    /**
     * One entry of a list field, as an array.
     *
     * @param  array<mixed>  $message
     * @return array<mixed>
     */
    protected function entry(array $message, string $key, int $index = 0): array
    {
        $list = $this->listOf($message, $key);

        $this->assertArrayHasKey($index, $list);
        $this->assertIsArray($list[$index]);

        return $list[$index];
    }

    private function id(string $api): string
    {
        $messages = $this->messages($api);

        $this->assertCount(1, $messages, 'expected exactly one delivered message');
        $this->assertIsArray($messages[0]);

        return $this->field($messages[0], 'ID');
    }
}
