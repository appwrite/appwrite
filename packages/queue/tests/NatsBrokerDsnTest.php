<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Utopia\DSN\DSN;
use Utopia\NATS\Connection;
use Utopia\NATS\JetStream\DiscardPolicy;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Broker\Provisioning;
use Utopia\Queue\Codec\Json;

/**
 * Building a broker from a `nats://` DSN. None of these connect: the connection
 * is opened on first use, and validation must fail before it.
 */
#[RequiresMethod(Connection::class, 'connect')]
final class NatsBrokerDsnTest extends TestCase
{
    public function testAnUntunedDsnSetsNothing(): void
    {
        $this->assertSame([], Nats::dsnArguments(new DSN('nats://host:4222')));
        $this->assertInstanceOf(Nats::class, Nats::fromDSN(new DSN('nats://host:4222')));
    }

    public function testEveryParameterIsCastToItsConstructorType(): void
    {
        $arguments = Nats::dsnArguments(new DSN(
            'nats://host:4222?replicas=3&maxDeliver=6&backoff=30,120,600&deadMaxAge=604800&duplicateWindow=900'
            . '&maxAge=3600&maxMsgSize=262144&maxMsgs=100000&maxBytes=1073741824&discard=new&maxAckPending=360&maxWaiting=16',
        ));
        \ksort($arguments);

        $expected = [
            'replicas' => 3,
            'maxDeliver' => 6,
            'deadMaxAge' => 604800.0,
            'duplicateWindow' => 900.0,
            'maxAge' => 3600.0,
            'maxMsgSize' => 262144,
            'maxMsgs' => 100000,
            'maxBytes' => 1073741824,
            'discard' => DiscardPolicy::New,
            'maxAckPending' => 360,
            'maxWaiting' => 16,
            'backoff' => [30.0, 120.0, 600.0],
            'ackWait' => 30.0,
        ];
        \ksort($expected);

        $this->assertSame($expected, $arguments);
    }

    public function testTheScheduleSetsTheAckWait(): void
    {
        $arguments = Nats::dsnArguments(new DSN('nats://host:4222?backoff=20,240,1200'));

        $this->assertSame(20.0, $arguments['ackWait']);
        $this->assertSame([20.0, 240.0, 1200.0], $arguments['backoff']);
    }

    public function testAnExplicitAckWaitRewritesTheSchedulesFirstStep(): void
    {
        $arguments = Nats::dsnArguments(new DSN('nats://host:4222?ackWait=45&backoff=20,240'));

        $this->assertSame(45.0, $arguments['ackWait']);
        $this->assertSame([45.0, 240.0], $arguments['backoff']);
    }

    public function testAMaxDeliverLoweredIntoTheScheduleTrimsIt(): void
    {
        $dsn = new DSN('nats://host:4222?maxDeliver=3&backoff=120,300,900');

        $this->assertSame([120.0, 300.0], Nats::dsnArguments($dsn)['backoff']);
        $this->assertInstanceOf(Nats::class, Nats::fromDSN($dsn));
    }

    public function testTheDefaultMaxDeliverTrimsALongSchedule(): void
    {
        // Five deliveries leave room for four steps.
        $this->assertSame([10.0, 20.0, 30.0, 40.0], Nats::dsnArguments(new DSN('nats://host:4222?backoff=10,20,30,40,50,60'))['backoff']);
    }

    public function testASingleDeliveryDropsTheSchedule(): void
    {
        $this->assertNull(Nats::dsnArguments(new DSN('nats://host:4222?maxDeliver=1&backoff=30,120'))['backoff']);
    }

    public function testValuesBelowTheirFloorAreRaisedToIt(): void
    {
        $this->assertSame(
            ['ackWait' => 1.0, 'maxDeliver' => 1, 'replicas' => 1],
            Nats::dsnArguments(new DSN('nats://host:4222?ackWait=0&maxDeliver=0&replicas=0')),
        );
        $this->assertSame([1.0, 60.0], Nats::dsnArguments(new DSN('nats://host:4222?backoff=0.5,60'))['backoff']);
    }

    public function testAZeroDeadMaxAgeKeepsDeadLettersForever(): void
    {
        $this->assertNull(Nats::dsnArguments(new DSN('nats://host:4222?deadMaxAge=0'))['deadMaxAge']);
    }

    public function testUnlimitedStaysExpressible(): void
    {
        $this->assertInstanceOf(Nats::class, Nats::fromDSN(new DSN('nats://host:4222?maxMsgs=-1&maxBytes=-1')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'a typo' => ['nats://host:4222?maxAckPendng=8'];
        yield 'inactiveThreshold, which silences a worker' => ['nats://host:4222?inactiveThreshold=86400'];
        yield 'a unit on seconds' => ['nats://host:4222?duplicateWindow=30seconds'];
        yield 'a unit on a count' => ['nats://host:4222?maxAckPending=8%20jobs'];
        yield 'a fractional count' => ['nats://host:4222?maxAckPending=4.9'];
        yield 'a list where one number goes' => ['nats://host:4222?ackWait=30,120'];
        yield 'a unit in the schedule' => ['nats://host:4222?backoff=30,2m,600'];
        yield 'an unknown discard policy' => ['nats://host:4222?discard=newest'];
        yield 'a repeated parameter' => ['nats://host:4222?replicas[]=1&replicas[]=3'];
        yield 'another scheme' => ['redis://host:6379?replicas=3'];
    }

    #[DataProvider('refused')]
    public function testAMalformedDsnIsRefusedBeforeTheCast(string $dsn): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Nats::dsnArguments(new DSN($dsn));
    }

    public function testTheConstructorStillRefusesWhatItCannotHonour(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('discard: New requires');

        Nats::fromDSN(new DSN('nats://host:4222?discard=new'));
    }

    public function testCodeIsPassedByName(): void
    {
        $this->assertInstanceOf(Nats::class, Nats::fromDSN(
            new DSN('nats://host:4222?replicas=3'),
            'worker-audits',
            onError: static function (\Throwable $error): void {
            },
            codec: new Json(),
            messageId: static fn (array $payload): string => 'id',
            provisioning: Provisioning::Require,
        ));
    }

    public function testADsnParameterCannotAlsoBePassedInCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('replicas is read from the DSN');

        Nats::fromDSN(new DSN('nats://host:4222'), replicas: 3);
    }

    public function testTheMaxDeliverIsReadable(): void
    {
        $this->assertSame(5, Nats::fromDSN(new DSN('nats://host:4222'))->getMaxDeliver());
        $this->assertSame(8, Nats::fromDSN(new DSN('nats://host:4222?maxDeliver=8'))->getMaxDeliver());
    }

    public function testConnectionOptionsCarryDecodedCredentialsAndTheName(): void
    {
        $options = Nats::connectionOptions(new DSN('nats://us%40r:p%40ss@host:4223?replicas=3'), 'edge/pod-1');

        $this->assertSame(['nats://host:4223'], $options->servers);
        $this->assertSame('us@r', $options->user);
        $this->assertSame('p@ss', $options->pass);
        $this->assertSame('edge/pod-1', $options->name);
    }

    public function testConnectionOptionsDefaultThePortAndSendNoCredentials(): void
    {
        $options = Nats::connectionOptions(new DSN('nats://host'));

        $this->assertSame(['nats://host:4222'], $options->servers);
        $this->assertNull($options->user);
        $this->assertNull($options->pass);
    }
}
