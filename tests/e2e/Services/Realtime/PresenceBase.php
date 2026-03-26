<?php

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\Console;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Roles;

abstract class PresenceBase extends Scope
{
    use RealtimeBase;
    use ProjectCustom;
    use SideClient;

    /**
     * @var array<string, array<float>>
     */
    private array $timings = [
        'createPresenceApi' => [],
        'createPresenceRealtime' => [],
        'listPresence' => [],
    ];

    abstract protected function getNumberOfUsersToCreate(): int;

    abstract protected function getListPresenceURL(): string;

    protected function getSeedPresenceDocumentsCount(): int
    {
        return 0;
    }

    public function testListPresenceBenchmarkWithPermissionGroups(): void
    {
        // Benchmark A - Real traffic benchmark:
        // - Create 500 presence docs through websocket (realtime path)
        // - Permissions: `read(any)` so all viewers should see all presences
        // - Send presence rounds every ~30s (configurable), and call listPresence 5 times per round

        $totalUsers = 500;
        $cycles = (int) (\getenv('PRESENCE_BENCH_CYCLES') ?: 2);
        $intervalSeconds = (int) (\getenv('PRESENCE_BENCH_INTERVAL_SECONDS') ?: 30);
        $occurrences = 5;
        $ownerDelayMs = (int) (\getenv('PRESENCE_BENCH_OWNER_DELAY_MS') ?: 5000);
        $ownerDelayMs = \max(0, $ownerDelayMs);

        $users = [];
        for ($i = 0; $i < $totalUsers; $i++) {
            $users[] = $this->getUser(true);
        }

        $viewer = $users[0];
        $permissions = [Permission::read(Role::any())];

        $realtimeConnections = $totalUsers * $cycles;
        $listCalls = $occurrences * $cycles;

        Console::info(\sprintf(
            '[Presence Benchmark A] totalUsers=%d cycles=%d intervalSeconds=%d realtimeConnections=%d listCalls=%d permissions=%s',
            $totalUsers,
            $cycles,
            $intervalSeconds,
            $realtimeConnections,
            $listCalls,
            \json_encode($permissions, JSON_UNESCAPED_SLASHES)
        ));

        $overallListElapsed = [];

        $expectedOwnerIds = [
            $users[0]['$id'],
            $users[(int) \floor($totalUsers / 2)]['$id'],
            $users[$totalUsers - 1]['$id'],
        ];

        for ($cycle = 0; $cycle < $cycles; $cycle++) {
            // Send presence updates for all owners (each owner gets one websocket connection).
            foreach ($users as $ownerIndex => $owner) {
                $status = 'benchA-presence-c' . $cycle . '-o' . $ownerIndex;
                $this->reportPresence($owner, $status, $permissions);
                if ($ownerDelayMs > 0) {
                    \usleep($ownerDelayMs);
                }
            }

            // Allow realtime write to settle.
            \usleep(100000);

            $cycleListElapsed = [];
            for ($i = 0; $i < $occurrences; $i++) {
                $benchmark = $this->fetchPresenceListAs($viewer);
                $this->assertEquals(200, $benchmark['response']['headers']['status-code']);
                $this->assertGreaterThan(0.0, $benchmark['elapsedMs']);

                $cycleListElapsed[] = $benchmark['elapsedMs'];
                $overallListElapsed[] = $benchmark['elapsedMs'];

                if ($i === $occurrences - 1) {
                    $rows = $this->extractPresenceRows($benchmark['response']['body']);
                    $visibleMap = $this->indexPresenceRowsByUserId($rows);

                    $this->assertEquals(
                        $totalUsers,
                        \count($visibleMap),
                        \sprintf(
                            'Expected viewer to see all %d presences, got %d',
                            $totalUsers,
                            \count($visibleMap)
                        )
                    );

                    foreach ($expectedOwnerIds as $ownerUserId) {
                        // We only validate statuses for a few sample users to keep the benchmark lightweight.
                        $this->assertEquals(
                            'benchA-presence-c' . $cycle . '-o' . $this->getOwnerIndexByUserId($users, $ownerUserId),
                            $visibleMap[$ownerUserId]['status'] ?? null
                        );
                    }

                    Console::info(\sprintf(
                        '[Presence Benchmark A] cycle=%d listPresence occurrence=%d elapsedMs=%.2f rows=%d',
                        $cycle,
                        $i + 1,
                        $benchmark['elapsedMs'],
                        \count($visibleMap)
                    ));
                } else {
                    Console::info(\sprintf(
                        '[Presence Benchmark A] cycle=%d listPresence occurrence=%d elapsedMs=%.2f',
                        $cycle,
                        $i + 1,
                        $benchmark['elapsedMs']
                    ));
                }
            }

            $avg = \array_sum($cycleListElapsed) / \max(1, \count($cycleListElapsed));
            Console::info(\sprintf(
                '[Presence Benchmark A] cycle=%d listPresence average over %d calls: %.2fms',
                $cycle,
                $occurrences,
                $avg
            ));

            if ($cycle < $cycles - 1) {
                \usleep($intervalSeconds * 1000000);
            }
        }

        $overallAvg = \array_sum($overallListElapsed) / \max(1, \count($overallListElapsed));
        Console::info(\sprintf(
            '[Presence Benchmark A Summary] totalUsers=%d pattern=read(any)->viewer sees all owners realtimeConnections=%d listCalls=%d listPresenceAvg=%.2fms',
            $totalUsers,
            $realtimeConnections,
            $listCalls,
            $overallAvg
        ));

        $this->printTimingSummary();
    }

    public function testListPresenceBenchmarkReadUsersWindow500100(): void
    {
        // Benchmark B - Rotation window permissions:
        // - Create 500 presence docs through websocket (realtime path)
        // - Permissions: for each owner, allow reading for exactly 100 viewers (1/5th)
        // - Send presence rounds every ~30s (configurable), and call listPresence 5 times per round

        $totalUsers = 500;
        $window = 100;
        $cycles = (int) (\getenv('PRESENCE_BENCH_CYCLES') ?: 2);
        $intervalSeconds = (int) (\getenv('PRESENCE_BENCH_INTERVAL_SECONDS') ?: 30);
        $occurrences = 5;
        $ownerDelayMs = (int) (\getenv('PRESENCE_BENCH_OWNER_DELAY_MS') ?: 5000);
        $ownerDelayMs = \max(0, $ownerDelayMs);

        $users = [];
        for ($i = 0; $i < $totalUsers; $i++) {
            $users[] = $this->getUser(true);
        }

        $viewerIndex = 0;
        $viewer = $users[$viewerIndex];

        $realtimeConnections = $totalUsers * $cycles;
        $listCalls = $occurrences * $cycles;

        Console::info(\sprintf(
            '[Presence Benchmark B] totalUsers=%d window=%d cycles=%d intervalSeconds=%d realtimeConnections=%d listCalls=%d',
            $totalUsers,
            $window,
            $cycles,
            $intervalSeconds,
            $realtimeConnections,
            $listCalls
        ));

        $expectedOwnerIndices = $this->getRotationExpectedOwnerIndices($viewerIndex, $totalUsers, $window);
        $expectedOwnerIds = \array_map(static fn (int $ownerIndex) => $users[$ownerIndex]['$id'], $expectedOwnerIndices);

        $sampleOwnerIndices = [
            $expectedOwnerIndices[0] ?? 0,
            $expectedOwnerIndices[(int) \floor(\count($expectedOwnerIndices) / 2)] ?? 0,
            $expectedOwnerIndices[\count($expectedOwnerIndices) - 1] ?? 0,
        ];

        $overallListElapsed = [];

        for ($cycle = 0; $cycle < $cycles; $cycle++) {
            foreach ($users as $ownerIndex => $owner) {
                $permissions = [];
                for ($offset = 0; $offset < $window; $offset++) {
                    $viewerForOwnerIndex = ($ownerIndex + $offset) % $totalUsers;
                    $permissions[] = Permission::read(Role::user($users[$viewerForOwnerIndex]['$id']));
                }

                $status = 'benchB-presence-c' . $cycle . '-o' . $ownerIndex;
                $this->reportPresence($owner, $status, $permissions);
                if ($ownerDelayMs > 0) {
                    \usleep($ownerDelayMs);
                }
            }

            \usleep(100000);

            $cycleListElapsed = [];
            for ($i = 0; $i < $occurrences; $i++) {
                $benchmark = $this->fetchPresenceListAs($viewer);
                $this->assertEquals(200, $benchmark['response']['headers']['status-code']);
                $this->assertGreaterThan(0.0, $benchmark['elapsedMs']);

                $cycleListElapsed[] = $benchmark['elapsedMs'];
                $overallListElapsed[] = $benchmark['elapsedMs'];

                if ($i === $occurrences - 1) {
                    $rows = $this->extractPresenceRows($benchmark['response']['body']);
                    $visibleMap = $this->indexPresenceRowsByUserId($rows);

                    $this->assertEquals(
                        \count($expectedOwnerIds),
                        \count($visibleMap),
                        'Expected viewer to see exactly window presences.'
                    );

                    foreach ($sampleOwnerIndices as $sampleOwnerIndex) {
                        $ownerUserId = $users[$sampleOwnerIndex]['$id'];
                        $expectedStatus = 'benchB-presence-c' . $cycle . '-o' . $sampleOwnerIndex;
                        $this->assertEquals(
                            $expectedStatus,
                            $visibleMap[$ownerUserId]['status'] ?? null
                        );
                    }

                    Console::info(\sprintf(
                        '[Presence Benchmark B] cycle=%d listPresence occurrence=%d elapsedMs=%.2f rows=%d',
                        $cycle,
                        $i + 1,
                        $benchmark['elapsedMs'],
                        \count($visibleMap)
                    ));
                } else {
                    Console::info(\sprintf(
                        '[Presence Benchmark B] cycle=%d listPresence occurrence=%d elapsedMs=%.2f',
                        $cycle,
                        $i + 1,
                        $benchmark['elapsedMs']
                    ));
                }
            }

            $avg = \array_sum($cycleListElapsed) / \max(1, \count($cycleListElapsed));
            Console::info(\sprintf(
                '[Presence Benchmark B] cycle=%d listPresence average over %d calls: %.2fms',
                $cycle,
                $occurrences,
                $avg
            ));

            if ($cycle < $cycles - 1) {
                \usleep($intervalSeconds * 1000000);
            }
        }

        $overallAvg = \array_sum($overallListElapsed) / \max(1, \count($overallListElapsed));
        Console::info(\sprintf(
            '[Presence Benchmark B Summary] totalUsers=%d window=%d pattern=viewer sees rotating window realtimeConnections=%d listCalls=%d listPresenceAvg=%.2fms',
            $totalUsers,
            $window,
            $realtimeConnections,
            $listCalls,
            $overallAvg
        ));

        $this->printTimingSummary();
    }

    public function testListPresenceBenchmarkRoleUsersUnverified500Users(): void
    {
        // Benchmark C - Role dimension unverified:
        // - Create 500 presence docs through websocket (realtime path)
        // - Permissions: read Role.users(unverified) for every owner
        // - Uses the default state where newly created users are expected to be unverified
        // - Send presence rounds every ~30s (configurable), and call listPresence 5 times per round

        $totalUsers = 500;
        $cycles = (int) (\getenv('PRESENCE_BENCH_CYCLES') ?: 2);
        $intervalSeconds = (int) (\getenv('PRESENCE_BENCH_INTERVAL_SECONDS') ?: 30);
        $occurrences = 5;
        $ownerDelayMs = (int) (\getenv('PRESENCE_BENCH_OWNER_DELAY_MS') ?: 5000);
        $ownerDelayMs = \max(0, $ownerDelayMs);

        $users = [];
        for ($i = 0; $i < $totalUsers; $i++) {
            $users[] = $this->getUser(true);
        }

        $viewer = $users[0];
        $permissions = [Permission::read(Role::users(Roles::DIMENSION_UNVERIFIED))];

        $realtimeConnections = $totalUsers * $cycles;
        $listCalls = $occurrences * $cycles;

        Console::info(\sprintf(
            '[Presence Benchmark C] totalUsers=%d cycles=%d intervalSeconds=%d realtimeConnections=%d listCalls=%d permissions=%s',
            $totalUsers,
            $cycles,
            $intervalSeconds,
            $realtimeConnections,
            $listCalls,
            \json_encode($permissions, JSON_UNESCAPED_SLASHES)
        ));

        $overallListElapsed = [];

        $sampleOwnerIndices = [
            0,
            (int) \floor($totalUsers / 2),
            $totalUsers - 1,
        ];

        for ($cycle = 0; $cycle < $cycles; $cycle++) {
            foreach ($users as $ownerIndex => $owner) {
                $status = 'benchC-presence-c' . $cycle . '-o' . $ownerIndex;
                $this->reportPresence($owner, $status, $permissions);
                if ($ownerDelayMs > 0) {
                    \usleep($ownerDelayMs);
                }
            }

            \usleep(100000);

            $cycleListElapsed = [];
            for ($i = 0; $i < $occurrences; $i++) {
                $benchmark = $this->fetchPresenceListAs($viewer);
                $this->assertEquals(200, $benchmark['response']['headers']['status-code']);
                $this->assertGreaterThan(0.0, $benchmark['elapsedMs']);

                $cycleListElapsed[] = $benchmark['elapsedMs'];
                $overallListElapsed[] = $benchmark['elapsedMs'];

                if ($i === $occurrences - 1) {
                    $rows = $this->extractPresenceRows($benchmark['response']['body']);
                    $visibleMap = $this->indexPresenceRowsByUserId($rows);

                    $this->assertEquals(
                        $totalUsers,
                        \count($visibleMap),
                        'Expected unverified viewer to see all presences.'
                    );

                    foreach ($sampleOwnerIndices as $sampleOwnerIndex) {
                        $ownerUserId = $users[$sampleOwnerIndex]['$id'];
                        $expectedStatus = 'benchC-presence-c' . $cycle . '-o' . $sampleOwnerIndex;
                        $this->assertEquals(
                            $expectedStatus,
                            $visibleMap[$ownerUserId]['status'] ?? null
                        );
                    }

                    Console::info(\sprintf(
                        '[Presence Benchmark C] cycle=%d listPresence occurrence=%d elapsedMs=%.2f rows=%d',
                        $cycle,
                        $i + 1,
                        $benchmark['elapsedMs'],
                        \count($visibleMap)
                    ));
                } else {
                    Console::info(\sprintf(
                        '[Presence Benchmark C] cycle=%d listPresence occurrence=%d elapsedMs=%.2f',
                        $cycle,
                        $i + 1,
                        $benchmark['elapsedMs']
                    ));
                }
            }

            $avg = \array_sum($cycleListElapsed) / \max(1, \count($cycleListElapsed));
            Console::info(\sprintf(
                '[Presence Benchmark C] cycle=%d listPresence average over %d calls: %.2fms',
                $cycle,
                $occurrences,
                $avg
            ));

            if ($cycle < $cycles - 1) {
                \usleep($intervalSeconds * 1000000);
            }
        }

        $overallAvg = \array_sum($overallListElapsed) / \max(1, \count($overallListElapsed));
        Console::info(\sprintf(
            '[Presence Benchmark C Summary] totalUsers=%d pattern=Role.users(unverified) viewer sees all realtimeConnections=%d listCalls=%d listPresenceAvg=%.2fms',
            $totalUsers,
            $realtimeConnections,
            $listCalls,
            $overallAvg
        ));

        $this->printTimingSummary();
    }

    public function testListPresenceBenchmarkRoleUsersVerified500Users(): void
    {
        // Benchmark D - Role dimension verified:
        // - Create 500 presence docs through websocket (realtime path)
        // - Permissions: read Role.users(verified) for every owner
        // - Marks all created users as verified via PATCH /users/:userId/verification
        // - Send presence rounds every ~30s (configurable), and call listPresence 5 times per round

        $totalUsers = 500;
        $cycles = (int) (\getenv('PRESENCE_BENCH_CYCLES') ?: 2);
        $intervalSeconds = (int) (\getenv('PRESENCE_BENCH_INTERVAL_SECONDS') ?: 30);
        $occurrences = 5;
        $ownerDelayMs = (int) (\getenv('PRESENCE_BENCH_OWNER_DELAY_MS') ?: 5000);
        $ownerDelayMs = \max(0, $ownerDelayMs);

        $users = [];
        for ($i = 0; $i < $totalUsers; $i++) {
            $users[] = $this->getUser(true);
        }

        // Make all users verified so Role.users(verified) matches requester roles.
        $this->setUsersEmailVerification($users, true);

        $viewer = $users[0];
        $permissions = [Permission::read(Role::users(Roles::DIMENSION_VERIFIED))];

        $realtimeConnections = $totalUsers * $cycles;
        $listCalls = $occurrences * $cycles;

        Console::info(\sprintf(
            '[Presence Benchmark D] totalUsers=%d cycles=%d intervalSeconds=%d realtimeConnections=%d listCalls=%d permissions=%s',
            $totalUsers,
            $cycles,
            $intervalSeconds,
            $realtimeConnections,
            $listCalls,
            \json_encode($permissions, JSON_UNESCAPED_SLASHES)
        ));

        $overallListElapsed = [];

        $sampleOwnerIndices = [
            0,
            (int) \floor($totalUsers / 2),
            $totalUsers - 1,
        ];

        for ($cycle = 0; $cycle < $cycles; $cycle++) {
            foreach ($users as $ownerIndex => $owner) {
                $status = 'benchD-presence-c' . $cycle . '-o' . $ownerIndex;
                $this->reportPresence($owner, $status, $permissions);
                if ($ownerDelayMs > 0) {
                    \usleep($ownerDelayMs);
                }
            }

            \usleep(100000);

            $cycleListElapsed = [];
            for ($i = 0; $i < $occurrences; $i++) {
                $benchmark = $this->fetchPresenceListAs($viewer);
                $this->assertEquals(200, $benchmark['response']['headers']['status-code']);
                $this->assertGreaterThan(0.0, $benchmark['elapsedMs']);

                $cycleListElapsed[] = $benchmark['elapsedMs'];
                $overallListElapsed[] = $benchmark['elapsedMs'];

                if ($i === $occurrences - 1) {
                    $rows = $this->extractPresenceRows($benchmark['response']['body']);
                    $visibleMap = $this->indexPresenceRowsByUserId($rows);

                    $this->assertEquals(
                        $totalUsers,
                        \count($visibleMap),
                        'Expected verified viewer to see all presences.'
                    );

                    foreach ($sampleOwnerIndices as $sampleOwnerIndex) {
                        $ownerUserId = $users[$sampleOwnerIndex]['$id'];
                        $expectedStatus = 'benchD-presence-c' . $cycle . '-o' . $sampleOwnerIndex;
                        $this->assertEquals(
                            $expectedStatus,
                            $visibleMap[$ownerUserId]['status'] ?? null
                        );
                    }

                    Console::info(\sprintf(
                        '[Presence Benchmark D] cycle=%d listPresence occurrence=%d elapsedMs=%.2f rows=%d',
                        $cycle,
                        $i + 1,
                        $benchmark['elapsedMs'],
                        \count($visibleMap)
                    ));
                } else {
                    Console::info(\sprintf(
                        '[Presence Benchmark D] cycle=%d listPresence occurrence=%d elapsedMs=%.2f',
                        $cycle,
                        $i + 1,
                        $benchmark['elapsedMs']
                    ));
                }
            }

            $avg = \array_sum($cycleListElapsed) / \max(1, \count($cycleListElapsed));
            Console::info(\sprintf(
                '[Presence Benchmark D] cycle=%d listPresence average over %d calls: %.2fms',
                $cycle,
                $occurrences,
                $avg
            ));

            if ($cycle < $cycles - 1) {
                \usleep($intervalSeconds * 1000000);
            }
        }

        $overallAvg = \array_sum($overallListElapsed) / \max(1, \count($overallListElapsed));
        Console::info(\sprintf(
            '[Presence Benchmark D Summary] totalUsers=%d pattern=Role.users(verified) viewer sees all realtimeConnections=%d listCalls=%d listPresenceAvg=%.2fms',
            $totalUsers,
            $realtimeConnections,
            $listCalls,
            $overallAvg
        ));

        $this->printTimingSummary();
    }

    public function testListPresenceBenchmarkAppendHistoryTwoPermissions500Users(): void
    {
        // Benchmark E - Append/history dataset (simulated via multiple presenceLogs per owner)
        // Setup:
        // - Create 500 owner users
        // - Mark 250 as verified, 250 as unverified
        // - For each owner, report twice:
        //   1) older version: permissions allowing unverified viewers
        //   2) newer version: permissions allowing verified viewers
        // Result:
        // - A "latest-only" listPresence implementation will let verified viewers see the newest versions,
        //   but unverified viewers will hit a permission denial and (currently) won't fall back to older ones.
        // - This benchmark measures listPresence latency for both groups.

        $totalUsers = 500;
        $verifiedCount = 250;
        $unverifiedCount = $totalUsers - $verifiedCount;
        $samplePerGroup = 5;

        $cycles = (int) (\getenv('PRESENCE_BENCH_HISTORY_CYCLES') ?: 1);
        $intervalSeconds = (int) (\getenv('PRESENCE_BENCH_INTERVAL_SECONDS') ?: 30);
        $versionDelayUs = (int) (\getenv('PRESENCE_BENCH_VERSION_DELAY_US') ?: 5000);
        $versionDelayUs = \max(0, $versionDelayUs);

        // Default to no extra delay between owners for this benchmark.
        // (You can throttle via PRESENCE_BENCH_OWNER_DELAY_MS if needed.)
        $ownerDelayMs = (int) (\getenv('PRESENCE_BENCH_OWNER_DELAY_MS') ?: 0);
        $ownerDelayMs = \max(0, $ownerDelayMs);

        $users = [];
        for ($i = 0; $i < $totalUsers; $i++) {
            $users[] = $this->getUser(true);
        }

        $verifiedOwners = \array_slice($users, 0, $verifiedCount);
        $unverifiedOwners = \array_slice($users, $verifiedCount, $unverifiedCount);

        $this->setUsersEmailVerification($verifiedOwners, true);
        $this->setUsersEmailVerification($unverifiedOwners, false);

        $permissionsOlder = [Permission::read(Role::users(Roles::DIMENSION_UNVERIFIED))];
        $permissionsNewer = [Permission::read(Role::users(Roles::DIMENSION_VERIFIED))];

        // Validate the "latest-only" semantics by checking known owner statuses.
        // Keep this small to avoid making the benchmark too slow.
        $sampleOwnerIndices = [
            0,
            (int) \floor($totalUsers / 2),
            $totalUsers - 1,
        ];

        Console::info(\sprintf(
            '[Presence Benchmark E] totalUsers=%d verifiedOwners=%d unverifiedOwners=%d cycles=%d intervalSeconds=%d samplePerGroup=%d ownerDelayMs=%d versionDelayUs=%d',
            $totalUsers,
            $verifiedCount,
            $unverifiedCount,
            $cycles,
            $intervalSeconds,
            $samplePerGroup,
            $ownerDelayMs,
            $versionDelayUs
        ));

        $verifiedListElapsed = [];
        $unverifiedListElapsed = [];

        for ($cycle = 0; $cycle < $cycles; $cycle++) {
            foreach ($users as $ownerIndex => $owner) {
                $statusOlder = 'benchE-older-unverified-c' . $cycle . '-o' . $ownerIndex;
                $this->reportPresence($owner, $statusOlder, $permissionsOlder);

                if ($versionDelayUs > 0) {
                    \usleep($versionDelayUs);
                }

                $statusNewer = 'benchE-newer-verified-c' . $cycle . '-o' . $ownerIndex;
                $this->reportPresence($owner, $statusNewer, $permissionsNewer);

                if ($ownerDelayMs > 0) {
                    \usleep($ownerDelayMs);
                }
            }

            // Allow realtime write to settle.
            \usleep(100);

            for ($i = 0; $i < $samplePerGroup; $i++) {
                $verifiedViewer = $verifiedOwners[$i];
                $unverifiedViewer = $unverifiedOwners[$i];

                // Verified viewer read
                $verifiedBenchmark = $this->fetchPresenceListAs($verifiedViewer);
                $this->assertEquals(200, $verifiedBenchmark['response']['headers']['status-code']);
                $this->assertGreaterThan(0.0, $verifiedBenchmark['elapsedMs']);

                $verifiedRows = $this->extractPresenceRows($verifiedBenchmark['response']['body']);
                $verifiedVisibleMap = $this->indexPresenceRowsByUserId($verifiedRows);

                $verifiedListElapsed[] = $verifiedBenchmark['elapsedMs'];
                Console::info(\sprintf(
                    '[Presence Benchmark E] cycle=%d verifiedViewerSample=%d elapsedMs=%.2f rows=%d',
                    $cycle,
                    $i,
                    $verifiedBenchmark['elapsedMs'],
                    \count($verifiedVisibleMap)
                ));

                $verifiedRowCount = \count($verifiedVisibleMap);
                $this->assertEquals(
                    $totalUsers,
                    $verifiedRowCount,
                    'Expected verified viewer to see all owners in this append/history benchmark.'
                );

                foreach ($sampleOwnerIndices as $ownerIndex) {
                    $ownerUserId = $users[$ownerIndex]['$id'];
                    $expectedStatus = 'benchE-newer-verified-c' . $cycle . '-o' . $ownerIndex;
                    $this->assertEquals(
                        $expectedStatus,
                        $verifiedVisibleMap[$ownerUserId]['status'] ?? null,
                        'Verified viewer status mismatch for ownerUserId=' . $ownerUserId
                    );
                }

                // Unverified viewer read
                $unverifiedBenchmark = $this->fetchPresenceListAs($unverifiedViewer);
                $this->assertEquals(200, $unverifiedBenchmark['response']['headers']['status-code']);
                $this->assertGreaterThan(0.0, $unverifiedBenchmark['elapsedMs']);

                $unverifiedRows = $this->extractPresenceRows($unverifiedBenchmark['response']['body']);
                $unverifiedVisibleMap = $this->indexPresenceRowsByUserId($unverifiedRows);

                $unverifiedListElapsed[] = $unverifiedBenchmark['elapsedMs'];
                Console::info(\sprintf(
                    '[Presence Benchmark E] cycle=%d unverifiedViewerSample=%d elapsedMs=%.2f rows=%d',
                    $cycle,
                    $i,
                    $unverifiedBenchmark['elapsedMs'],
                    \count($unverifiedVisibleMap)
                ));

                $unverifiedRowCount = \count($unverifiedVisibleMap);
                if ($unverifiedRowCount === 0) {
                    $this->assertEquals(0, $unverifiedRowCount, 'Expected unverified viewer to see 0 rows (latest-only semantics).');
                } else {
                    // If/when fallback-to-older-readable is implemented, unverified should see all owners.
                    $this->assertEquals(
                        $totalUsers,
                        $unverifiedRowCount,
                        'Expected unverified viewer to see all owners when fallback-to-older-readable is implemented.'
                    );

                    foreach ($sampleOwnerIndices as $ownerIndex) {
                        $ownerUserId = $users[$ownerIndex]['$id'];
                        $expectedStatus = 'benchE-older-unverified-c' . $cycle . '-o' . $ownerIndex;
                        $this->assertEquals(
                            $expectedStatus,
                            $unverifiedVisibleMap[$ownerUserId]['status'] ?? null,
                            'Unverified viewer status mismatch for ownerUserId=' . $ownerUserId
                        );
                    }
                }
            }

            if ($cycle < $cycles - 1) {
                \usleep($intervalSeconds);
            }
        }

        $verifiedAvg = \array_sum($verifiedListElapsed) / \max(1, \count($verifiedListElapsed));
        $unverifiedAvg = \array_sum($unverifiedListElapsed) / \max(1, \count($unverifiedListElapsed));

        Console::info(\sprintf(
            '[Presence Benchmark E Summary] totalUsers=%d pattern=two presenceLogs per owner (older: unverified permissions, newer: verified permissions); listPresence calls per cycle=%d (verified=%d, unverified=%d); listPresenceAvg verified=%.2fms unverified=%.2fms',
            $totalUsers,
            $samplePerGroup * 2,
            $samplePerGroup,
            $samplePerGroup,
            $verifiedAvg,
            $unverifiedAvg
        ));

        $this->printTimingSummary();
    }

    public function testListPresenceBenchmarkStatusPerViewerGroup500UsersFetch10(): void
    {
        // Benchmark F - group-based status:
        // - Create 500 users
        // - Partition viewers into 5 groups of 100 users each
        // - Each owner gets a single presence with:
        //   - permissions allowing exactly the viewer group it belongs to (100 viewers)
        //   - a status string encoding the viewer group
        // - For 10 sampled viewers (2 per group), fetch listPresence and assert:
        //   - the viewer sees exactly 100 owners
        //   - sample owners have the expected status

        $totalUsers = 500;
        $groupSize = 100;
        $numGroups = (int) (\floor($totalUsers / $groupSize));
        $viewerCount = 10; // 2 viewers per group

        $cycles = (int) (\getenv('PRESENCE_BENCH_GROUP_CYCLES') ?: 1);
        $intervalSeconds = (int) (\getenv('PRESENCE_BENCH_INTERVAL_SECONDS') ?: 30);
        $occurrences = (int) (\getenv('PRESENCE_BENCH_OCCURRENCES') ?: 5);

        $ownerDelayMs = (int) (\getenv('PRESENCE_BENCH_OWNER_DELAY_MS') ?: 0);
        $ownerDelayMs = \max(0, $ownerDelayMs);

        // Pick 2 viewers from each group: first two indices within group.
        $viewerIndices = [];
        for ($g = 0; $g < $numGroups && \count($viewerIndices) < $viewerCount; $g++) {
            $base = $g * $groupSize;

            $viewerIndices[] = $base;
            if (\count($viewerIndices) >= $viewerCount) {
                break;
            }

            // Add the next index within the group if it exists.
            if ($base + 1 < $totalUsers) {
                $viewerIndices[] = $base + 1;
            }
        }

        $statusSampleOwnerOffsets = [0, 50, 99];

        $users = [];
        for ($i = 0; $i < $totalUsers; $i++) {
            $users[] = $this->getUser(true);
        }

        // Precompute permissions per viewer group to avoid rebuilding huge permission arrays repeatedly.
        $permissionsByViewerGroup = [];
        for ($g = 0; $g < $numGroups; $g++) {
            $permissions = [];
            $start = $g * $groupSize;
            $end = $start + $groupSize;
            for ($viewerIndex = $start; $viewerIndex < $end; $viewerIndex++) {
                $permissions[] = Permission::read(Role::user($users[$viewerIndex]['$id']));
            }
            $permissionsByViewerGroup[$g] = $permissions;
        }

        $realtimeConnections = $totalUsers * $cycles;
        $listCalls = $viewerCount * $occurrences * $cycles;

        Console::info(\sprintf(
            '[Presence Benchmark F] totalUsers=%d groupSize=%d numGroups=%d viewerCount=%d cycles=%d occurrences=%d intervalSeconds=%d realtimeConnections=%d listCalls=%d ownerDelayMs=%d',
            $totalUsers,
            $groupSize,
            $numGroups,
            $viewerCount,
            $cycles,
            $occurrences,
            $intervalSeconds,
            $realtimeConnections,
            $listCalls,
            $ownerDelayMs
        ));

        $listElapsed = [];

        for ($cycle = 0; $cycle < $cycles; $cycle++) {
            // Send presence updates for all owners.
            foreach ($users as $ownerIndex => $owner) {
                $ownerGroup = (int) \floor($ownerIndex / $groupSize);
                $permissions = $permissionsByViewerGroup[$ownerGroup];
                $status = 'benchF-g' . $ownerGroup . '-o' . $ownerIndex . '-c' . $cycle;
                $this->reportPresence($owner, $status, $permissions);

                if ($ownerDelayMs > 0) {
                    \usleep($ownerDelayMs * 1000);
                }
            }

            // Allow realtime write to settle.
            \usleep(100000);

            // Fetch listPresence `occurrences` times per viewer, in-order.
            foreach ($viewerIndices as $viewerSampleIndex) {
                $viewer = $users[$viewerSampleIndex];
                $group = (int) \floor($viewerSampleIndex / $groupSize);

                for ($occurrence = 0; $occurrence < $occurrences; $occurrence++) {
                    $benchmark = $this->fetchPresenceListAs($viewer);
                    $this->assertEquals(200, $benchmark['response']['headers']['status-code']);
                    $this->assertGreaterThan(0.0, $benchmark['elapsedMs']);
                    $listElapsed[] = $benchmark['elapsedMs'];

                    $rows = $this->extractPresenceRows($benchmark['response']['body']);
                    $visibleMap = $this->indexPresenceRowsByUserId($rows);

                    $this->assertEquals(
                        $groupSize,
                        \count($visibleMap),
                        'Expected viewer group=' . $group . ' to see exactly ' . $groupSize . ' owners.'
                    );

                    // Validate status for sample owners only (keeps runtime reasonable).
                    foreach ($statusSampleOwnerOffsets as $offset) {
                        $ownerIndex = ($group * $groupSize) + $offset;
                        $ownerUserId = $users[$ownerIndex]['$id'];
                        $expectedStatus = 'benchF-g' . $group . '-o' . $ownerIndex . '-c' . $cycle;
                        $this->assertEquals(
                            $expectedStatus,
                            $visibleMap[$ownerUserId]['status'] ?? null,
                            'Status mismatch for viewerGroup=' . $group . ' ownerIndex=' . $ownerIndex
                        );
                    }
                }

                Console::info(\sprintf(
                    '[Presence Benchmark F] cycle=%d viewerIndex=%d viewerGroup=%d occurrences=%d',
                    $cycle,
                    $viewerSampleIndex,
                    $group,
                    $occurrences
                ));
            }
        }

        $avg = \array_sum($listElapsed) / \max(1, \count($listElapsed));
        Console::info(\sprintf(
            '[Presence Benchmark F Summary] totalUsers=%d pattern=viewerGroupStatus; realtimeConnections=%d listCalls=%d listPresenceAvg=%.2fms',
            $totalUsers,
            $realtimeConnections,
            $listCalls,
            $avg
        ));

        $this->printTimingSummary();
    }

    private function seedPresenceLoad(): void
    {
        $seedCount = \max(0, $this->getSeedPresenceDocumentsCount());

        if ($seedCount === 0) {
            return;
        }

        for ($i = 0; $i < $seedCount; $i++) {
            $seedUser = $this->getUser(true);

            $this->createPresenceViaApi(
                $seedUser,
                'seed-load-' . $i,
                [Permission::read(Role::user($seedUser['$id']))]
            );
        }
    }

    private function reportPresence(array $user, string $status, array $permissions): void
    {
        $start = \microtime(true);
        $projectId = $this->getProject()['$id'];
        $client = $this->getWebsocket(['account'], [
            'origin' => 'http://localhost',
            'cookie' => 'a_session_' . $projectId . '=' . $user['session'],
        ]);

        // connected payload
        $connectedPayloadRaw = $client->receive();
        $connectedPayload = \json_decode((string) $connectedPayloadRaw, true);
        $this->assertIsArray($connectedPayload, 'Expected websocket connected payload JSON.');
        $this->assertEquals('connected', $connectedPayload['type'] ?? null, 'Websocket connection did not succeed.');

        $client->send(\json_encode([
            'type' => 'presence',
            'data' => [
                'session' => $user['session'],
                'status' => $status,
                'permissions' => $permissions,
            ],
        ]));

        $presencePayloadRaw = $client->receive();
        $presencePayload = \json_decode((string) $presencePayloadRaw, true);
        $this->assertIsArray($presencePayload, 'Expected websocket presence payload JSON.');
        $this->assertEquals('presence', $presencePayload['type'] ?? null, 'Presence ack type mismatch.');

        $client->close();

        $elapsedMs = (\microtime(true) - $start) * 1000;
        $this->recordTiming('createPresenceRealtime', $elapsedMs);
    }

    private function createPresenceViaApi(array $user, string $status, array $permissions): void
    {
        $projectId = $this->getProject()['$id'];
        $headers = [
            'content-type' => 'application/json',
            'origin' => 'http://localhost',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $user['session'],
        ];

        $payload = [
            'status' => $status,
            'permissions' => $permissions,
        ];

        $start = \microtime(true);

        $response = $this->client->call(Client::METHOD_POST, '/iterative/presence', $headers, $payload);

        $elapsedMs = (\microtime(true) - $start) * 1000;
        $this->recordTiming('createPresenceApi', $elapsedMs);
        $this->assertEquals(
            201,
            $response['headers']['status-code'],
            'Create presence failed: ' . json_encode($response['body'] ?? [])
        );
    }

    private function fetchPresenceListAs(array $user): array
    {
        $projectId = $this->getProject()['$id'];

        $start = \microtime(true);
        $response = $this->client->call(Client::METHOD_GET, $this->getListPresenceURL(), [
            'content-type' => 'application/json',
            'origin' => 'http://localhost',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_' . $projectId . '=' . $user['session'],
        ]);
        $elapsedMs = (\microtime(true) - $start) * 1000;
        $this->recordTiming('listPresence', $elapsedMs);

        return [
            'elapsedMs' => $elapsedMs,
            'response' => $response,
        ];
    }

    private function extractPresenceRows(array $body): array
    {
        foreach (['presences', 'presence', 'documents', 'rows'] as $key) {
            $value = $body[$key] ?? null;
            if (\is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    private function indexPresenceRowsByUserId(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $userId = $row['userId'] ?? null;
            if (!\is_string($userId) || $userId === '') {
                continue;
            }

            $indexed[$userId] = $row;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $users
     */
    private function getOwnerIndexByUserId(array $users, string $userId): int
    {
        foreach ($users as $index => $user) {
            if (($user['$id'] ?? null) === $userId) {
                return (int) $index;
            }
        }

        $this->fail('Could not find owner userId=' . $userId);

        return 0;
    }

    private function getRotationExpectedOwnerIndices(int $viewerIndex, int $totalUsers, int $window): array
    {
        $indices = [];
        for ($offset = 0; $offset < $window; $offset++) {
            $indices[] = ($viewerIndex - $offset + $totalUsers) % $totalUsers;
        }

        return $indices;
    }

    /**
     * @param array<int, array<string, mixed>> $users
     */
    private function setUsersEmailVerification(array $users, bool $emailVerification): void
    {
        $projectId = $this->getProject()['$id'];

        // Route `/v1/users/:userId/verification` only supports ADMIN or KEY auth
        // (not normal user sessions), so use the console session cookie.
        $adminHeaders = [
            'content-type' => 'application/json',
            'origin' => 'http://localhost',
            'x-appwrite-project' => $projectId,
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin',
        ];

        foreach ($users as $user) {
            $response = $this->client->call(
                Client::METHOD_PATCH,
                '/users/' . $user['$id'] . '/verification',
                $adminHeaders,
                [
                'emailVerification' => $emailVerification,
                ],
            );

            $this->assertEquals(
                200,
                $response['headers']['status-code'],
                'Failed to update user emailVerification for userId=' . $user['$id']
            );
        }
    }

    private function recordTiming(string $operation, float $elapsedMs): void
    {
        $this->timings[$operation][] = $elapsedMs;
    }

    private function printTimingSummary(): void
    {
        foreach ($this->timings as $operation => $values) {
            if (empty($values)) {
                continue;
            }

            $avg = \array_sum($values) / \count($values);
            $min = \min($values);
            $max = \max($values);

            \fwrite(
                \STDOUT,
                \sprintf(
                    "\n[Presence Benchmark] %s count=%d avg=%.2fms min=%.2fms max=%.2fms\n",
                    $operation,
                    \count($values),
                    $avg,
                    $min,
                    $max
                )
            );
        }
    }
}
