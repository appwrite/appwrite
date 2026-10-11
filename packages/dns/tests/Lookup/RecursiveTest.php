<?php

declare(strict_types=1);

namespace Utopia\DNS\Tests\Lookup;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Utopia\DNS\Lookup\Recursive;
use Utopia\DNS\Message;
use Utopia\DNS\Message\Record;

final class RecursiveTest extends TestCase
{
    public function testServersMustBeIpAddresses(): void
    {
        foreach ([[], ['dns.google'], ['8.8.8.8', 'resolver:53'], ['[::1]:x'], ['[::1'], ['[::1]53'], ['1.1.1.1:'], ['1.1.1.1:65536'], ['1.1.1.1:-1']] as $servers) {
            try {
                new Recursive($servers);
                $this->fail('Expected ' . \json_encode($servers) . ' to be refused.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAcceptsAddressesWithAndWithoutPorts(): void
    {
        new Recursive(['1.1.1.1', '1.1.1.1:5353', '2606:4700:4700::1111', '[2606:4700:4700::1111]', '[::1]:5353']);

        $this->addToAssertionCount(1);
    }

    #[RunInSeparateProcess]
    public function testFailsOverPastAFailingOrUnreachableServer(): void
    {
        $answers = [Record::TYPE_A => '93.184.215.14', Record::TYPE_AAAA => '2606:2800:21f:cb07:6820:80da:af6b:8b2c'];

        $this->withServers([
            'unreachable',
            fn (Message $query): Message => Message::response($query->header, 2, $query->questions), // SERVFAIL
            fn (Message $query): Message => $this->answer($query, $answers),
        ], function (Recursive $dns) use ($answers): void {
            $this->assertEqualsCanonicalizing(\array_values($answers), $dns->addresses('example.test'));
        });
    }

    #[RunInSeparateProcess]
    public function testFailsClosedWhenOneAddressFamilyFails(): void
    {
        // A answers, AAAA fails: trusting half the list could hide a private AAAA
        $this->withServers([
            fn (Message $query): Message => $query->questions[0]->type === Record::TYPE_A
                ? $this->answer($query, [Record::TYPE_A => '93.184.215.14'])
                : Message::response($query->header, 5, $query->questions), // REFUSED
        ], function (Recursive $dns): void {
            $this->assertSame([], $dns->addresses('example.test'));
        });
    }

    #[RunInSeparateProcess]
    public function testANameThatDoesNotExistResolvesToNothing(): void
    {
        $this->withServers([
            fn (Message $query): Message => Message::response($query->header, 3, $query->questions), // NXDOMAIN
        ], function (Recursive $dns): void {
            $this->assertSame([], $dns->addresses('nowhere.test'));
        });
    }

    /**
     * @param array<int, string> $answers record type => address
     */
    private function answer(Message $query, array $answers): Message
    {
        $question = $query->questions[0];
        $records = isset($answers[$question->type])
            ? [new Record($question->name, $question->type, ttl: 60, rdata: $answers[$question->type])]
            : [];

        return Message::response($query->header, 0, $query->questions, $records);
    }

    /**
     * Runs $test in a hooked coroutine against local TCP DNS servers, one per behaviour:
     * a callable answering each query, or 'unreachable'.
     *
     * @param list<(\Closure(Message): Message)|string> $behaviours  a Closure answering each query, or "unreachable"
     * @param callable(Recursive): void $test
     */
    private function withServers(array $behaviours, callable $test): void
    {
        // A failed assertion inside the coroutine would kill the process; rethrow it here
        $failure = null;
        Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        Coroutine\run(function () use ($behaviours, $test, &$failure): void {
            $servers = [];
            $addresses = [];
            foreach ($behaviours as $behaviour) {
                $server = \stream_socket_server('tcp://127.0.0.1:0');
                $this->assertNotFalse($server);
                $address = \stream_socket_get_name($server, false);
                $this->assertIsString($address);
                $addresses[] = $address;
                if (!$behaviour instanceof \Closure) {
                    \fclose($server); // the port is now closed: connections are refused
                    continue;
                }
                $servers[] = $server;
                Coroutine::create(function () use ($server, $behaviour): void {
                    while ($connection = @\stream_socket_accept($server, 5)) {
                        $prefix = \unpack('n', (string) \fread($connection, 2));
                        $length = \is_array($prefix) && \is_int($prefix[1] ?? null) ? $prefix[1] : 0;
                        $response = $behaviour(Message::decode((string) \fread($connection, \max(1, $length))))->encode();
                        \fwrite($connection, \pack('n', \strlen($response)) . $response);
                        \fclose($connection);
                    }
                });
            }

            try {
                $test(new Recursive($addresses));
            } catch (\Throwable $thrown) {
                $failure = $thrown;
            } finally {
                foreach ($servers as $server) {
                    \fclose($server);
                }
            }
        });

        if ($failure !== null) {
            throw $failure;
        }
    }
}
