<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Projects;

use Appwrite\Database\Factory as DatabaseFactory;
use Appwrite\Event\Event;
use Appwrite\Maintenance\Cohort;
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
 * Every project gets maintenance through schedule-maintenance -> worker-maintenance -> worker-deletes, on one cycle for
 * projects accessed in the last 30 days and another for the rest: an expired presence row in the project is deleted.
 *
 * task-maintenance no longer enqueues projects, so only the cohort's sweep can delete the row. The maintenance queue's
 * success counter only shows that a worker consumed jobs from that queue while the test waited. A cycle reaches every
 * project of its cohort once per interval, so each test only runs when its interval is at most one five-minute slice,
 * as CI sets both.
 */
final class ProjectMaintenanceTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private const string SCHEDULER = 'appwrite-task-scheduler';

    private const string WORKER = 'appwrite-worker';

    private const int TIMEOUT = 300;

    public function testActiveProjectGetsMaintenance(): void
    {
        $this->assertMaintained(Cohort::Active);
    }

    public function testInactiveProjectGetsMaintenance(): void
    {
        $this->assertMaintained(Cohort::Inactive);
    }

    private function assertMaintained(Cohort $cohort): void
    {
        [$variable, $default] = match ($cohort) {
            Cohort::Active => ['_APP_MAINTENANCE_INTERVAL', '86400'],
            Cohort::Inactive => ['_APP_MAINTENANCE_INACTIVE_INTERVAL', '604800'],
        };

        $scheduler = $this->containerEnvironment(self::SCHEDULER);
        if ($scheduler === null) {
            $this->requireContainer('The ' . self::SCHEDULER . ' container is not running, so nothing schedules project maintenance');
        }

        $worker = $this->containerEnvironment(self::WORKER);
        if ($worker === null) {
            $this->requireContainer('The ' . self::WORKER . ' container is not running, so nothing consumes the maintenance queue');
        }
        $queue = ($worker['_APP_MAINTENANCE_QUEUE_NAME'] ?? '') ?: Event::MAINTENANCE_QUEUE_NAME;

        $interval = (int) (($scheduler[$variable] ?? '') ?: $default);
        if ($interval > self::TIMEOUT) {
            $this->markTestSkipped($variable . ' is ' . $interval . ' seconds, longer than the ' . self::TIMEOUT . ' seconds this test waits for the scheduler to reach a project');
        }

        $project = $this->getProject(true);
        [$presenceId, $expiresAt] = $this->createExpiringPresence($project['$id'], $cohort === Cohort::Inactive ? 6 : 2);

        // The calls above stamp accessedAt as they finish, so let that settle before overwriting it. The row still
        // expires after the project is placed in its cohort, so no other cohort's sweep can reach it first. From here
        // on any API call to an inactive project would refresh accessedAt and move it back to the active projects.
        while ($cohort === Cohort::Inactive && \time() < $expiresAt - 2) {
            \usleep(100_000);
        }
        $accessedAt = match ($cohort) {
            Cohort::Active => DateTime::now(),
            Cohort::Inactive => DateTime::addSeconds(new \DateTime(), -60 * 86400),
        };
        $this->withFactory(function (DatabaseFactory $factory) use ($project, $accessedAt): void {
            $factory->platform()->updateDocument('projects', $project['$id'], new Document([
                'accessedAt' => $accessedAt,
            ]));
        });

        $processed = $this->countProcessedJobs($queue);

        while (\time() <= $expiresAt) {
            \usleep(100_000);
        }

        $this->assertEventually(function () use ($project, $presenceId, $cohort): void {
            $this->assertSame(0, $this->countPresenceRows($project['$id'], $presenceId), 'the scheduler maintained the ' . $cohort->value . ' project and deleted its expired presence');
        }, self::TIMEOUT * 1_000, 1_000);

        $this->assertGreaterThan($processed, $this->countProcessedJobs($queue), 'a worker committed at least one job from the ' . $queue . ' queue while the test waited');

        $stored = '';
        $this->withFactory(function (DatabaseFactory $factory) use ($project, &$stored): void {
            $stored = (string) $factory->platform()->getDocument('projects', $project['$id'])->getAttribute('accessedAt', '');
        });
        $cutoff = \time() - 30 * 86400;
        $stamp = (new \DateTime($stored))->getTimestamp();
        if ($cohort === Cohort::Active) {
            $this->assertGreaterThanOrEqual($cutoff, $stamp, 'the project stayed active for the whole wait');
        } else {
            $this->assertLessThan($cutoff, $stamp, 'the project stayed inactive for the whole wait');
        }
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
    private function createExpiringPresence(string $projectId, int $seconds): array
    {
        $key = $this->client->call(Client::METHOD_POST, '/projects/' . $projectId . '/keys', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'keyId' => Id::unique(),
            'name' => 'Project maintenance',
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
        $expiresAt = DateTime::addSeconds(new \DateTime(), $seconds);
        $presence = $this->client->call(Client::METHOD_PUT, '/presences/' . Id::unique(), $headers, [
            'userId' => $user['body']['$id'],
            'status' => 'online',
            'expiresAt' => $expiresAt,
        ]);
        $this->assertSame(200, $presence['headers']['status-code']);

        return [$presence['body']['$id'], (new \DateTime($expiresAt))->getTimestamp()];
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
