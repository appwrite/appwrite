<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Exception\ConnectionException;
use Utopia\SMTP\Tls;
use Utopia\SMTP\Transport\Swoole;
use Utopia\SMTP\Verification;

/**
 * The same ground NativeTest covers, against the same kind of listener, because
 * the two transports make the same promises and only one of them was being held
 * to them.
 *
 * Every body runs inside a scheduler: a coroutine client cannot be built
 * outside one. Failures are carried back out rather than left to escape the
 * coroutine, where Swoole would turn an assertion into a fatal.
 */
#[RequiresPhpExtension('swoole')]
final class SwooleTest extends TestCase
{
    /** @var resource|null */
    private $server;

    /** @var resource|null */
    private $peer;

    protected function tearDown(): void
    {
        foreach ([$this->peer, $this->server] as $handle) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->peer = null;
        $this->server = null;
    }

    private function coroutine(callable $body): void
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

    private function listen(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);

        if ($server === false) {
            $this->fail("Could not listen: {$error}");
        }

        $this->server = $server;
        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }

    /**
     * A connected transport, and the listener's side of the same connection.
     */
    private function connect(): Swoole
    {
        $transport = new Swoole('127.0.0.1', $this->listen());
        $transport->connect(2.0, false);

        $peer = stream_socket_accept($this->server(), 2);

        if ($peer === false) {
            $this->fail('The listener never saw the connection');
        }

        $this->peer = $peer;

        return $transport;
    }

    public function testWritesReachTheOtherEnd(): void
    {
        $this->coroutine(function (): void {
            $transport = $this->connect();
            $transport->write("EHLO tests\r\n", 2.0);

            $this->assertSame("EHLO tests\r\n", fgets($this->peer()));

            $transport->close();
        });
    }

    public function testReadsWhatTheOtherEndSent(): void
    {
        $this->coroutine(function (): void {
            $transport = $this->connect();
            fwrite($this->peer(), "220 ready\r\n");

            $this->assertSame('220 ready', rtrim($transport->read(8192, 2.0), "\r\n"));

            $transport->close();
        });
    }

    /**
     * Unlike the stream transport, this one takes whole datagrams off the
     * socket and hands them out in slices, so what a short read leaves behind
     * has to still be there for the next one.
     */
    public function testKeepsWhatOneReadDidNotTake(): void
    {
        $this->coroutine(function (): void {
            $transport = $this->connect();
            fwrite($this->peer(), 'abcdef');

            $this->assertSame('ab', $transport->read(2, 2.0));
            $this->assertSame('cd', $transport->read(2, 2.0));
            $this->assertSame('ef', $transport->read(2, 2.0));

            $transport->close();
        });
    }

    public function testCarriesAPayloadLargerThanOneWrite(): void
    {
        $this->coroutine(function (): void {
            $transport = $this->connect();
            $payload = str_repeat('abcdefgh', 4096); // 32 KiB

            $transport->write($payload, 2.0);

            $read = '';

            while (\strlen($read) < \strlen($payload)) {
                $chunk = fread($this->peer(), 8192);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $read .= $chunk;
            }

            $this->assertSame($payload, $read, 'every byte has to arrive, in order');

            $transport->close();
        });
    }

    public function testReadFailsWhenTheServerHangsUp(): void
    {
        $this->coroutine(function (): void {
            $transport = $this->connect();
            fclose($this->peer());
            $this->peer = null;

            $this->expectException(ConnectionException::class);

            $transport->read(8192, 2.0);
        });
    }

    public function testReadRejectsANonPositiveLength(): void
    {
        $this->coroutine(function (): void {
            $transport = $this->connect();

            $this->expectException(\InvalidArgumentException::class);

            $transport->read(0, 2.0);
        });
    }

    public function testReadingBeforeConnectingFails(): void
    {
        $this->coroutine(function (): void {
            $this->expectException(\LogicException::class);

            (new Swoole('127.0.0.1', 1))->read(8192, 2.0);
        });
    }

    public function testReadingAfterCloseFails(): void
    {
        $this->coroutine(function (): void {
            $transport = $this->connect();
            $transport->close();

            $this->expectException(\LogicException::class);

            $transport->read(8192, 2.0);
        });
    }

    public function testConnectingToNobodyFails(): void
    {
        $this->coroutine(function (): void {
            $this->expectException(ConnectionException::class);

            // Port 1 rather than an ephemeral one nobody happens to hold: on
            // Linux loopback, connecting to a free port inside the ephemeral
            // range can pair with the source port the kernel just handed us and
            // succeed against itself.
            (new Swoole('127.0.0.1', 1))->connect(2.0, false);
        });
    }

    public function testAWriteBudgetReachesTheClient(): void
    {
        // send() takes no deadline argument, so the only place a write budget
        // can land is the client's own settings. Asking the client is the only
        // way to know it was not quietly dropped.
        $this->coroutine(function (): void {
            $transport = $this->connect();
            $transport->write("NOOP\r\n", 7.5);

            $client = (new \ReflectionProperty(Swoole::class, 'client'))->getValue($transport);
            $this->assertInstanceOf(\Swoole\Coroutine\Client::class, $client);

            $settings = $client->setting;
            $this->assertIsArray($settings);
            $this->assertArrayHasKey('write_timeout', $settings);
            $this->assertEqualsWithDelta(7.5, $settings['write_timeout'], PHP_FLOAT_EPSILON);

            $transport->close();
        });
    }

    public function testRefusesToVerifyACertificateAgainstAnAddress(): void
    {
        // X509_check_host() reads the DNS entries of a certificate, not the
        // address ones, so this can never pass and says so rather than failing
        // in the handshake with nothing useful.
        $this->coroutine(function (): void {
            $transport = new Swoole('127.0.0.1', $this->listen(), new Tls(verify: Verification::SelfSigned));

            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessageMatches('/peerName/');

            $transport->connect(2.0, true);
        });
    }

    public function testAcceptsANameTheCertificateCouldCarry(): void
    {
        // Naming the certificate's own subject is the way through, and the
        // check happens before anything is dialled.
        $this->coroutine(function (): void {
            $transport = new Swoole(
                '127.0.0.1',
                $this->listen(),
                new Tls(verify: Verification::SelfSigned, peerName: 'mail.example.test'),
            );

            // Nothing is speaking TLS on the other end, so the handshake is what
            // fails now -- not the name check.
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessageMatches('/^((?!peerName).)*$/s');

            $transport->connect(2.0, true);
        });
    }

    public function testAnAddressIsFineWhenNothingIsBeingChecked(): void
    {
        $this->coroutine(function (): void {
            $transport = new Swoole('127.0.0.1', $this->listen(), new Tls(verify: Verification::None));
            $transport->connect(2.0, false);

            $this->assertFalse($transport->isTls());

            $transport->close();
        });
    }

    public function testAPlainConnectionDoesNotClaimToBeEncrypted(): void
    {
        $this->coroutine(function (): void {
            $transport = $this->connect();

            $this->assertFalse($transport->isTls());

            $transport->close();
        });
    }

    public function testCloseIsSafeToRepeat(): void
    {
        $this->coroutine(function (): void {
            $transport = $this->connect();

            $transport->close();
            $transport->close();

            $this->assertFalse($transport->isTls());
        });
    }

    /**
     * @return resource
     */
    private function peer()
    {
        $this->assertIsResource($this->peer);

        return $this->peer;
    }

    /**
     * @return resource
     */
    private function server()
    {
        $this->assertIsResource($this->server);

        return $this->server;
    }
}
