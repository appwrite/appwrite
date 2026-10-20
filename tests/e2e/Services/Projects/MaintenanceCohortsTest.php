<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Projects;

use Appwrite\Database\Provisioner;
use Appwrite\Maintenance\Cadence;
use Appwrite\Maintenance\Sweep;
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

use function Swoole\Coroutine\run;

/**
 * A project not accessed in the last 30 days must still get maintenance: its expired presence row is deleted,
 * while the same row in a recently accessed project survives a run of the inactive cohort.
 *
 * The appwrite-task-maintenance container keeps sweeping both cohorts on a schedule fixed by its environment. A tick
 * whose slot owns either project would delete a row before the trigger could, so the test skips from five minutes
 * after such a tick, which may run late behind earlier sweeps, to fifteen minutes before it.
 */
final class MaintenanceCohortsTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private const string CONTAINER = 'appwrite-task-maintenance';

    public function testIdleProjectGetsMaintenance(): void
    {
        $idle = $this->getProject(true);
        $active = $this->getProject(true);

        [$idlePresenceId, $idleExpiresAt] = $this->createExpiringPresence($idle['$id']);
        [$activePresenceId, $activeExpiresAt] = $this->createExpiringPresence($active['$id']);

        // Any API call to these projects from here on would refresh accessedAt.
        $expiresAt = \max($idleExpiresAt, $activeExpiresAt);
        while (\time() <= $expiresAt) {
            \usleep(100_000);
        }

        $this->skipNearTick($idle['$id'], $active['$id']);
        $this->assertSame(1, $this->countPresenceRows($idle['$id'], $idlePresenceId), 'the idle row outlives its expiry until maintenance');
        $this->assertSame(1, $this->countPresenceRows($active['$id'], $activePresenceId), 'the control row outlives its expiry until maintenance');

        $this->withFactory(function (Provisioner $factory) use ($idle): void {
            $factory->platform()->updateDocument('projects', $idle['$id'], new Document([
                'accessedAt' => DateTime::addSeconds(new \DateTime(), -60 * 86400),
            ]));
        });

        $this->assertMatchesRegularExpression('/\binactive cohort: .*, 1 enqueued,/', $this->trigger('inactive', $idle['$id']));
        $this->assertMatchesRegularExpression('/\binactive cohort: .*, 0 enqueued,/', $this->trigger('inactive', $active['$id']));
        $this->assertMatchesRegularExpression('/\bactive cohort: .*, 0 enqueued,/', $this->trigger('active', $idle['$id']));

        $this->assertEventually(function () use ($idle, $idlePresenceId): void {
            $this->assertSame(0, $this->countPresenceRows($idle['$id'], $idlePresenceId), "the idle project's expired presence is deleted");
        }, 60_000, 1_000);

        $deadline = \microtime(true) + 15;
        do {
            $this->assertSame(1, $this->countPresenceRows($active['$id'], $activePresenceId), 'the inactive cohort left the active project alone');
            \usleep(1_000_000);
        } while (\microtime(true) < $deadline);
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
            'name' => 'Maintenance cohorts',
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

    private function skipNearTick(string $idleId, string $activeId): void
    {
        $environment = $this->loopEnvironment();
        if ($environment === null) {
            return;
        }

        $cadence = Cadence::fromIntervals(
            (int) ($environment['_APP_MAINTENANCE_INTERVAL'] ?? '86400'),
            (int) ($environment['_APP_MAINTENANCE_INACTIVE_INTERVAL'] ?? '604800'),
        );
        $startTime = $environment['_APP_MAINTENANCE_START_TIME'] ?? '00:00';

        // The control never joins the inactive cohort; the idle project joins it once accessedAt is backdated.
        $cohorts = [
            'active' => [$cadence->activeSlots, [$idleId, $activeId]],
            'inactive' => [$cadence->inactiveSlots, [$idleId]],
        ];

        $owners = [];
        $this->withFactory(function (Provisioner $factory) use ($cohorts, &$owners): void {
            $platform = $factory->platform();
            foreach ($cohorts as $cohort => [$slots, $projectIds]) {
                foreach ($projectIds as $projectId) {
                    $owners[$cohort][$projectId] = Sweep::owner($platform->getDocument('projects', $projectId), $slots, $platform->getIdAttributeType());
                }
            }
        });

        $anchor = Cadence::anchor($startTime, new \DateTimeZone(\date_default_timezone_get()));
        $now = \time();
        foreach ($cohorts as $cohort => [$slots]) {
            foreach ($owners[$cohort] as $projectId => $owner) {
                $boundary = $cadence->reaches($owner, $slots, $anchor, $now - 301, $now + 900);
                if ($boundary !== null) {
                    $this->markTestSkipped("The maintenance loop sweeps project {$projectId} in the {$cohort} cohort at " . \gmdate('H:i', $boundary) . ' UTC');
                }
            }
        }
    }

    /**
     * @return array<string, string>|null the loop container's environment, or null when no loop is running
     */
    private function loopEnvironment(): ?array
    {
        $stdout = '';
        $stderr = '';
        $command = (new Command('docker'))
            ->argument('inspect')
            ->argument('--format')
            ->argument('{{json .Config.Env}}')
            ->argument(self::CONTAINER);

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

    private function trigger(string $cohort, string $projectId): string
    {
        $stdout = '';
        $stderr = '';
        $command = (new Command('docker'))
            ->argument('exec')
            ->argument('appwrite')
            ->argument('maintenance')
            ->argument('--type=trigger')
            ->argument('--cohort=' . $cohort)
            ->argument('--project=' . $projectId);

        $code = Console::execute($command, '', $stdout, $stderr);
        $this->assertSame(0, $code, "Maintenance failed with code {$code}: {$stderr} ({$stdout})");

        return $stdout;
    }

    private function countPresenceRows(string $projectId, string $presenceId): int
    {
        $count = -1;
        $this->withFactory(function (Provisioner $factory) use ($projectId, $presenceId, &$count): void {
            $project = $factory->platform()->getDocument('projects', $projectId);
            $count = \count($factory->project($project)->find('presenceLogs', [Query::equal('$id', [$presenceId])]));
        });

        return $count;
    }

    /**
     * @param callable(Provisioner): void $callback
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
