<?php

declare(strict_types=1);

namespace Utopia\Abuse\Tests;

use Override;
use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;

final class DatabaseSchemaTest extends TestCase
{
    private const int SECONDS = 3600;

    private const int WINDOW = 1_767_225_600;

    private Database $database;

    #[Override]
    protected function setUp(): void
    {
        $this->database = new Database(new Memory(), new Cache(new NoCache()));
        $this->database->setDatabase('abuse')->setNamespace('schema');
        $this->database->create();

        $this->adapter('setup', 1)->setup();
    }

    public function testCountsHitsPerKeyAcrossRequests(): void
    {
        $this->assertFalse($this->check('login', 2), 'first hit is within the limit');
        $this->assertFalse($this->check('login', 2), 'second hit is within the limit');
        $this->assertTrue($this->check('login', 2), 'third hit exceeds the limit');
        $this->assertTrue($this->check('login', 2), 'the window stays exhausted');

        $this->assertFalse($this->check('signup', 2), 'another key has its own count');
    }

    public function testEarlierWindowDoesNotCountTowardsTheCurrentOne(): void
    {
        $this->assertFalse($this->check('login', 2));
        $this->store('login', self::WINDOW - self::SECONDS, 2);

        $this->assertFalse($this->check('login', 2), 'the exhausted earlier window is not counted');
        $this->assertTrue($this->check('login', 2), 'the current window reaches its own limit');
    }

    public function testOneRowPerKeyAndWindow(): void
    {
        $this->assertFalse($this->check('login', 5));
        $this->assertSame([self::WINDOW], $this->windows('login'), 'the hit is stored in its window');

        $this->store('login', self::WINDOW - self::SECONDS, 1);
        $this->store('signup', self::WINDOW, 1);

        $this->expectException(Duplicate::class);
        $this->store('login', self::WINDOW, 1);
    }

    public function testCleanupRemovesOnlyEarlierWindows(): void
    {
        $this->assertFalse($this->check('login', 5));
        $this->store('login', self::WINDOW - self::SECONDS, 3);

        new Abuse($this->adapter('login', 5))->cleanup(self::WINDOW);

        $this->assertSame([self::WINDOW], $this->windows('login'), 'only the current window is left');
    }

    public function testSetupAgainKeepsExistingCounts(): void
    {
        $this->assertFalse($this->check('login', 1));

        $this->adapter('login', 1)->setup();

        $this->assertTrue($this->check('login', 1), 'the count survives a second setup');
    }

    private function adapter(string $key, int $limit): TimeLimit\Database
    {
        return new class ($key, $limit, self::SECONDS, $this->database, self::WINDOW) extends TimeLimit\Database {
            public function __construct(string $key, int $limit, int $seconds, Database $db, int $window)
            {
                parent::__construct($key, $limit, $seconds, $db);
                $this->timestamp = $window;
            }
        };
    }

    private function check(string $key, int $limit): bool
    {
        return new Abuse($this->adapter($key, $limit))->check();
    }

    /**
     * @return list<int>
     */
    private function windows(string $key): array
    {
        $windows = [];
        foreach (new Abuse($this->adapter($key, 1))->getLogs() as $log) {
            $time = $log->getAttribute('time');
            if ($log->getAttribute('key') === $key && \is_string($time)) {
                $windows[] = new \DateTime($time)->getTimestamp();
            }
        }
        \sort($windows);

        return $windows;
    }

    private function store(string $key, int $timestamp, int $count): void
    {
        $this->database->getAuthorization()->skip(fn (): Document => $this->database->createDocument(
            TimeLimit\Database::COLLECTION,
            new Document(['$permissions' => [], 'key' => $key, 'time' => $this->format($timestamp), 'count' => $count]),
        ));
    }

    private function format(int $timestamp): string
    {
        return DateTime::format(new \DateTime()->setTimestamp($timestamp));
    }
}
