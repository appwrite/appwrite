<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Projects;

use Appwrite\Database\Factory as DatabaseFactory;
use Appwrite\Event\Event;
use Swoole\Coroutine;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Console\Command;
use Utopia\Console\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;

use function Swoole\Coroutine\run;

/**
 * A project not accessed in the last 30 days still gets maintenance: its expired presence row is deleted, while the
 * same row in a recently accessed project survives.
 *
 * The path is schedule-maintenance -> worker-maintenance -> worker-deletes. The deleted idle row and the surviving
 * control row are what prove the sweep; the maintenance queue's success counter only shows that a worker consumed
 * jobs from that queue while the test waited. The scheduler reaches every inactive project once per
 * _APP_MAINTENANCE_INACTIVE_INTERVAL, so the test only runs when that interval is at most one five-minute slice, as
 * CI sets it.
 */
final class InactiveProjectMaintenanceTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private const string SCHEDULER = 'appwrite-task-scheduler';

    private const string WORKER = 'appwrite-worker';

    private const string DAILY = 'appwrite-task-maintenance';

    private const int TIMEOUT = 300;

    public function testInactiveProjectGetsMaintenance(): void
    {
        $scheduler = $this->containerEnvironment(self::SCHEDULER);
        if ($scheduler === null) {
            $this->requireContainer('The ' . self::SCHEDULER . ' container is not running, so nothing schedules inactive projects');
        }

        $worker = $this->containerEnvironment(self::WORKER);
        if ($worker === null) {
            $this->requireContainer('The ' . self::WORKER . ' container is not running, so nothing consumes the maintenance queue');
        }
        $queue = ($worker['_APP_MAINTENANCE_QUEUE_NAME'] ?? '') ?: Event::MAINTENANCE_QUEUE_NAME;

        $interval = (int) ($scheduler['_APP_MAINTENANCE_INACTIVE_INTERVAL'] ?? '604800');
        if ($interval > self::TIMEOUT) {
            $this->markTestSkipped('_APP_MAINTENANCE_INACTIVE_INTERVAL is ' . $interval . ' seconds, longer than the ' . self::TIMEOUT . ' seconds this test waits for the scheduler to reach a project');
        }

        $idle = $this->getProject(true);
        $active = $this->getProject(true);

        [$idlePresenceId, $idleExpiresAt] = $this->createExpiringPresence($idle['$id']);
        [$activePresenceId, $activeExpiresAt] = $this->createExpiringPresence($active['$id']);

        // Any API call to the idle project from here on would refresh accessedAt and move it back to the active projects.
        $expiresAt = \max($idleExpiresAt, $activeExpiresAt);
        while (\time() <= $expiresAt) {
            \usleep(100_000);
        }

        $this->skipNearDailyRun();

        $this->assertSame(1, $this->countPresenceRows($idle['$id'], $idlePresenceId), 'the idle row outlives its expiry until maintenance');
        $this->assertSame(1, $this->countPresenceRows($active['$id'], $activePresenceId), 'the control row outlives its expiry until maintenance');

        $processed = $this->countProcessedJobs($queue);

        $this->withFactory(function (DatabaseFactory $factory) use ($idle): void {
            $factory->platform()->updateDocument('projects', $idle['$id'], new Document([
                'accessedAt' => DateTime::addSeconds(new \DateTime(), -60 * 86400),
            ]));
        });

        $this->assertEventually(function () use ($idle, $idlePresenceId): void {
            $this->assertSame(0, $this->countPresenceRows($idle['$id'], $idlePresenceId), 'the scheduler maintained the inactive project and deleted its expired presence');
        }, self::TIMEOUT * 1_000, 1_000);

        $this->assertSame(1, $this->countPresenceRows($active['$id'], $activePresenceId), 'the scheduler left the recently accessed project to the daily maintenance');
        $this->assertGreaterThan($processed, $this->countProcessedJobs($queue), 'a worker committed at least one job from the ' . $queue . ' queue while the test waited');

        $accessedAt = '';
        $this->withFactory(function (DatabaseFactory $factory) use ($idle, &$accessedAt): void {
            $accessedAt = (string) $factory->platform()->getDocument('projects', $idle['$id'])->getAttribute('accessedAt', '');
        });
        $this->assertLessThan(\time() - 30 * 86400, (new \DateTime($accessedAt))->getTimestamp(), 'the idle project stayed inactive for the whole wait');
    }

    private function requireContainer(string $message): never
    {
        if (\getenv('CI') !== false) {
            $this->fail($message);
        }
        $this->markTestSkipped($message);
    }

    /**
     * The broker's count of jobs the queue's consumers committed, which only a worker settling a job moves.
     */
    private function countProcessedJobs(string $queue): int
    {
        $redis = new \Redis();
        $redis->connect(System::getEnv('_APP_REDIS_HOST', 'redis'), (int) System::getEnv('_APP_REDIS_PORT', '6379'));
        $password = System::getEnv('_APP_REDIS_PASS', '');
        if ($password !== '') {
            $user = System::getEnv('_APP_REDIS_USER', '');
            $redis->auth($user !== '' ? [$user, $password] : $password);
        }

        try {
            return (int) $redis->get('utopia-queue.stats.' . $queue . '.success');
        } finally {
            $redis->close();
        }
    }

    /**
     * @return array{string, int} the presence's id and its expiry as a unix timestamp
     */
    private function createExpiringPresence(string $projectId): array
    {
        $key = $this->client->call(Client::METHOD_POST, '/projects/' . $projectId . '/keys', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'keyId' => Id::unique(),
            'name' => 'Inactive project maintenance',
            'scopes' => ['users.read', 'users.write', 'presences.read', 'presences.write'],
        ]);
        $this->assertSame(201, $key['headers']['status-code']);

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key['body']['secret'],
        ];

        $user = $this->client->call(Client::METHOD_POST, '/users', $headers, [
            'userId' => Id::unique(),
            'email' => Id::unique() . '@localhost.test',
            'password' => 'password',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);

        // Stamped immediately before the call: the API refuses an expiry already in the past.
        $expiresAt = DateTime::addSeconds(new \DateTime(), 2);
        $presence = $this->client->call(Client::METHOD_PUT, '/presences/' . Id::unique(), $headers, [
            'userId' => $user['body']['$id'],
            'status' => 'online',
            'expiresAt' => $expiresAt,
        ]);
        $this->assertSame(200, $presence['headers']['status-code']);

        return [$presence['body']['$id'], (new \DateTime($expiresAt))->getTimestamp()];
    }

    /**
     * The daily maintenance deletes the control's expired row too, so a run between five minutes ago (it may still be
     * working through a backlog) and fifteen minutes from now would make the control assertion meaningless.
     */
    private function skipNearDailyRun(): void
    {
        $environment = $this->containerEnvironment(self::DAILY);
        if ($environment === null) {
            return;
        }

        $interval = \max(1, (int) ($environment['_APP_MAINTENANCE_INTERVAL'] ?? '86400'));
        $startTime = $environment['_APP_MAINTENANCE_START_TIME'] ?? '00:00';

        $now = \time();
        $from = $now - 301;
        $until = $now + 900;
        $anchor = (new \DateTime('today ' . $startTime, new \DateTimeZone(\date_default_timezone_get())))->getTimestamp();
        $run = $anchor + (int) \ceil(($from - $anchor) / $interval) * $interval;

        if ($run <= $until) {
            $this->markTestSkipped('The daily maintenance runs at ' . \gmdate('H:i', $run) . ' UTC, inside the window this test needs it to stay out of');
        }
    }

    /**
     * @return array<string, string>|null the container's environment, or null when it is not running
     */
    private function containerEnvironment(string $container): ?array
    {
        $stdout = '';
        $stderr = '';
        $command = (new Command('docker'))
            ->argument('inspect')
            ->argument('--format')
            ->argument('{{json .Config.Env}}')
            ->argument($container);

        if (Console::execute($command, '', $stdout, $stderr) !== 0) {
            return null;
        }

        $environment = [];
        foreach (\json_decode($stdout, true, flags: JSON_THROW_ON_ERROR) as $variable) {
            [$name, $value] = \explode('=', $variable, 2) + [1 => ''];
            $environment[$name] = $value;
        }

        return $environment;
    }

    private function countPresenceRows(string $projectId, string $presenceId): int
    {
        $count = -1;
        $this->withFactory(function (DatabaseFactory $factory) use ($projectId, $presenceId, &$count): void {
            $project = $factory->platform()->getDocument('projects', $projectId);
            $count = \count($factory->project($project)->find('presenceLogs', [Query::equal('$id', [$presenceId])]));
        });

        return $count;
    }

    /**
     * @param callable(DatabaseFactory): void $callback receives the server's database factory
     */
    private function withFactory(callable $callback): void
    {
        $read = function () use ($callback): void {
            global $register;
            $authorization = new Authorization();
            $factory = $register->get('databaseFactory')($register->get('pools'), $register->get('cache'), $authorization);

            $authorization->skip(fn () => $callback($factory));
        };

        if (Coroutine::getCid() >= 0) {
            $read();
        } else {
            run($read);
        }
    }
}
