<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\MFA;

use Appwrite\Auth\MFA\Challenge\TOTP;
use Appwrite\Auth\MFA\Type;
use OTPHP\TOTP as TOTPLibrary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\IntegerWidth;

final class TOTPTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    private const string AUTHENTICATOR = 'authenticator';

    private const int PERIOD = 30;

    /**
     * A moment ten seconds into a time step.
     */
    private const int NOW = 1_800_000_010;

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function steps(): iterable
    {
        yield 'current step' => [0, true];
        yield 'previous step' => [-1, true];
        yield 'next step' => [1, true];
        yield 'two steps back' => [-2, false];
        yield 'two steps ahead' => [2, false];
    }

    #[DataProvider('steps')]
    public function testCodeIsAcceptedOnlyWithinOneStepOfNow(int $offset, bool $accepted): void
    {
        $step = TOTP::stepAt($this->user(), $this->code($offset), self::NOW);

        $this->assertSame($accepted ? $this->step($offset) : null, $step);
    }

    public function testPreviousStepCodeIsRejectedOnceItsDriftHasPassed(): void
    {
        $lastSecondOfStep = self::NOW + 19;

        $this->assertNull(TOTP::stepAt($this->user(), $this->code(-1), $lastSecondOfStep));
    }

    public function testWrongCodeIsRejected(): void
    {
        $this->assertNull(TOTP::stepAt($this->user(), '000000', self::NOW));
    }

    public function testCodeIsRedeemedOnce(): void
    {
        $database = $this->database();
        $code = $this->code(0);

        $this->assertTrue(TOTP::redeem($database, $this->user(), $code, self::NOW));
        $this->assertFalse(TOTP::redeem($database, $this->user(), $code, self::NOW));
        $this->assertSame($this->step(0), $this->lastStep($database));
    }

    public function testCodeFromALaterStepIsRedeemedAfterAnEarlierOne(): void
    {
        $database = $this->database();

        $this->assertTrue(TOTP::redeem($database, $this->user(), $this->code(-1), self::NOW));
        $this->assertTrue(TOTP::redeem($database, $this->user(), $this->code(0), self::NOW));
        $this->assertSame($this->step(0), $this->lastStep($database));
    }

    public function testCodeFromAnEarlierStepIsRejectedAfterALaterOne(): void
    {
        $database = $this->database();

        $this->assertTrue(TOTP::redeem($database, $this->user(), $this->code(1), self::NOW));
        $this->assertFalse(TOTP::redeem($database, $this->user(), $this->code(0), self::NOW));
        $this->assertSame($this->step(1), $this->lastStep($database));
    }

    public function testWrongCodeLeavesTheLastStepUnchanged(): void
    {
        $database = $this->database();

        $this->assertFalse(TOTP::redeem($database, $this->user(), '000000', self::NOW));
        $this->assertNull($this->lastStep($database));
    }

    private function code(int $offset): string
    {
        return TOTPLibrary::create(self::SECRET)->at(self::NOW + $offset * self::PERIOD);
    }

    private function step(int $offset): int
    {
        return \intdiv(self::NOW, self::PERIOD) + $offset;
    }

    private function user(): Document
    {
        return new Document([
            'authenticators' => [
                new Document([
                    '$id' => self::AUTHENTICATOR,
                    'type' => Type::TOTP,
                    'data' => ['secret' => self::SECRET],
                ]),
            ],
        ]);
    }

    private function database(): Database
    {
        $database = new Database(new MemoryAdapter(), new Cache(new MemoryCache()));
        $database
            ->setDatabase('appwrite')
            ->setNamespace('_totp');
        $database->create();
        $database->createCollection(Collection::create(
            id: 'authenticators',
            attributes: [Attribute::integer(key: 'lastStep', width: IntegerWidth::Bits64)],
        ));
        $database->getAuthorization()->skip(fn () => $database->createDocument('authenticators', new Document([
            '$id' => self::AUTHENTICATOR,
        ])));
        $database->getAuthorization()->disable();

        return $database;
    }

    private function lastStep(Database $database): mixed
    {
        return $database->getDocument('authenticators', self::AUTHENTICATOR)->getAttribute('lastStep');
    }
}
