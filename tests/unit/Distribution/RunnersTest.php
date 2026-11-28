<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution;

use Appwrite\Distribution\Runner\Platform;
use Appwrite\Distribution\Runner\Status;
use Appwrite\Distribution\Runner\Token;
use Appwrite\Distribution\Runners;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Distribution\Fakes\LeaseDatabase;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

final class RunnersTest extends TestCase
{
    private const array ENVIRONMENT = [
        '_APP_OPENSSL_KEY_V1' => 'test-openssl-key',
        '_APP_REGION' => 'fra',
    ];

    /** @var array<string, string|false> */
    private array $environment = [];

    private LeaseDatabase $dbForPlatform;

    private Runners $runners;

    protected function setUp(): void
    {
        foreach (self::ENVIRONMENT as $name => $value) {
            $this->environment[$name] = \getenv($name);
            \putenv("{$name}={$value}");
        }

        $this->dbForPlatform = new LeaseDatabase();
        $this->runners = new Runners($this->dbForPlatform);
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            \putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    public function testSecretIsDerivedFromTheKeyAndEmptyWithoutIt(): void
    {
        $this->assertSame(\hash_hmac('sha256', 'distribution:runner', 'test-openssl-key'), Runners::secret());

        \putenv('_APP_OPENSSL_KEY_V1=');

        $this->assertSame('', Runners::secret());
    }

    public function testEnrolStoresAnOnlineRunnerWithTheTokenHash(): void
    {
        ['runner' => $runner, 'token' => $token] = $this->runners->enrol('mac-mini-1', Platform::Macos, ['xcode-16.2', 'xcode-16.2', 'arm64'], 2, '1.0.0');

        $verified = Token::verify($token, Runners::secret());
        $this->assertInstanceOf(Token::class, $verified);
        $this->assertSame($runner->getId(), $verified->runnerId);
        $this->assertSame([
            'name' => 'mac-mini-1',
            'platform' => 'macos',
            'labels' => ['xcode-16.2', 'arm64'],
            'secret' => \hash('sha256', $token),
            'status' => Status::Online->value,
            'version' => '1.0.0',
            'capacity' => 2,
            'region' => 'fra',
        ], \array_intersect_key($runner->getArrayCopy(), \array_flip(['name', 'platform', 'labels', 'secret', 'status', 'version', 'capacity', 'region'])));
        $this->assertNotSame($token, $runner->getAttribute('secret'));
        $this->assertNotEmpty($runner->getAttribute('seenAt'));
    }

    public function testEnrolWithoutTheKeyStoresNothing(): void
    {
        \putenv('_APP_OPENSSL_KEY_V1=');

        try {
            $this->runners->enrol('mac-mini-1', Platform::Macos, [], 1, '1.0.0');
            $this->fail('Enrolling without the key must throw.');
        } catch (\LogicException $error) {
            $this->assertSame(Token::KEY_REQUIRED, $error->getMessage());
        }

        $this->assertSame([], $this->dbForPlatform->documents);
    }

    public function testHeldReturnsTheEnrolledRunner(): void
    {
        ['runner' => $runner, 'token' => $token] = $this->enrolled();

        $this->assertSame($runner->getId(), $this->runners->held($this->verify($token), $token)->getId());
    }

    public function testHeldRejectsARotatedToken(): void
    {
        ['token' => $token] = $this->enrolled();
        $verified = $this->verify($token);
        $rotated = Token::issue($verified->runnerId, Runners::secret());

        $this->assertHeldRefused($verified, $rotated, Exception::USER_UNAUTHORIZED);
    }

    public function testHeldRejectsAnotherRunnersToken(): void
    {
        ['token' => $token] = $this->enrolled();
        ['runner' => $other] = $this->enrolled();

        $this->assertHeldRefused(new Token($other->getId()), $token, Exception::USER_UNAUTHORIZED);
    }

    public function testHeldRejectsAnUnknownRunner(): void
    {
        ['token' => $token] = $this->enrolled();

        $this->assertHeldRefused(new Token('runner9'), $token, Exception::DISTRIBUTION_RUNNER_NOT_FOUND);
    }

    public function testBeatRecordsTheStatusVersionAndLabels(): void
    {
        ['runner' => $runner] = $this->enrolled();
        $this->stale($runner->getId(), 600);

        $beaten = $this->runners->beat($runner, Status::Draining, '1.1.0', ['xcode-16.3']);

        $this->assertSame(Status::Draining->value, $beaten->getAttribute('status'));
        $this->assertSame('1.1.0', $beaten->getAttribute('version'));
        $this->assertSame(['xcode-16.3'], $beaten->getAttribute('labels'));
        $this->assertGreaterThan(\time() - 5, (new \DateTimeImmutable($beaten->getAttribute('seenAt')))->getTimestamp());
    }

    public function testBeatKeepsTheVersionAndLabelsItWasNotGiven(): void
    {
        ['runner' => $runner] = $this->enrolled();

        $beaten = $this->runners->beat($runner, Status::Offline);

        $this->assertSame(Status::Offline->value, $beaten->getAttribute('status'));
        $this->assertSame('1.0.0', $beaten->getAttribute('version'));
        $this->assertSame(['xcode-16.2'], $beaten->getAttribute('labels'));
    }

    public function testTouchWritesAtMostEveryThirtySeconds(): void
    {
        $now = \time();
        ['runner' => $runner] = $this->enrolled();
        $writes = $this->dbForPlatform->writes;

        $this->assertSame($runner->getAttribute('seenAt'), $this->runners->touch($runner, $now + Runners::TOUCH - 1)->getAttribute('seenAt'));
        $this->assertSame($writes, $this->dbForPlatform->writes);

        $touched = $this->runners->touch($runner, $now + Runners::TOUCH + 5);

        $this->assertSame($writes + 1, $this->dbForPlatform->writes);
        $this->assertGreaterThan($runner->getAttribute('seenAt'), $touched->getAttribute('seenAt'));
    }

    public function testIsAvailableCountsOnlineAndDrainingRunners(): void
    {
        ['runner' => $runner] = $this->enrolled();

        $this->assertTrue($this->runners->isAvailable(Platform::Macos, ['xcode-16.2'], \time()));

        $runner = $this->runners->beat($runner, Status::Draining);
        $this->assertTrue($this->runners->isAvailable(Platform::Macos, ['xcode-16.2'], \time()));

        $this->runners->beat($runner, Status::Offline);
        $this->assertFalse($this->runners->isAvailable(Platform::Macos, ['xcode-16.2'], \time()));
    }

    public function testIsAvailableNeedsARunnerSeenWithinTheWindow(): void
    {
        ['runner' => $runner] = $this->enrolled();
        $this->stale($runner->getId(), Runners::SEEN - 5);

        $this->assertTrue($this->runners->isAvailable(Platform::Macos, [], \time()));
        $this->assertFalse($this->runners->isAvailable(Platform::Macos, [], \time() + 10));
    }

    public function testIsAvailableNeedsEveryLabel(): void
    {
        $this->enrolled();

        $this->assertTrue($this->runners->isAvailable(Platform::Macos, [], \time()));
        $this->assertFalse($this->runners->isAvailable(Platform::Macos, ['xcode-15.4'], \time()));
        $this->assertFalse($this->runners->isAvailable(Platform::Macos, ['xcode-16.2', 'arm64'], \time()));
    }

    public function testIsAvailableWithoutRunners(): void
    {
        $this->assertFalse($this->runners->isAvailable(Platform::Macos, [], \time()));
    }

    /**
     * @return array{runner: Document, token: string}
     */
    private function enrolled(): array
    {
        return $this->runners->enrol('mac-mini-1', Platform::Macos, ['xcode-16.2'], 1, '1.0.0');
    }

    private function verify(string $token): Token
    {
        $verified = Token::verify($token, Runners::secret());
        $this->assertInstanceOf(Token::class, $verified);

        return $verified;
    }

    private function stale(string $runnerId, int $seconds): void
    {
        $this->dbForPlatform->documents['distributionRunners'][$runnerId]->setAttribute(
            'seenAt',
            DateTime::format((new \DateTime())->setTimestamp(\time() - $seconds)),
        );
    }

    private function assertHeldRefused(Token $verified, string $token, string $type): void
    {
        try {
            $this->runners->held($verified, $token);
            $this->fail('The runner must be refused.');
        } catch (Exception $error) {
            $this->assertSame($type, $error->getType());
        }
    }
}
