<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Utopia\Database\Query;

trait BuildsBase
{
    private const string SOURCES = "name: fixture\nenvironment:\n  sdk: '>=3.0.0 <4.0.0'\n";

    private const int RECONCILE_TICK = 70_000;

    public function testCreateBuildWithoutRepository(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $target = $this->createTarget($appId);
        $this->assertSame(201, $target['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $created = $this->createBuild($appId, ['targetId' => $target['body']['$id']]);
        $this->assertSame(400, $created['headers']['status-code']);
        $this->assertSame('distribution_repository_missing', $created['body']['type']);

        $listed = $this->listBuilds($appId);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertSame(0, $listed['body']['total']);
        $this->assertSame([], $listed['body']['builds']);

        $missing = $this->createBuild('missing-' . \uniqid(), ['targetId' => $target['body']['$id']]);
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missing['body']['type']);
    }

    public function testGetUnknownBuild(): void
    {
        $app = $this->createApp();
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];
        $buildId = 'missing-' . \uniqid();

        /**
         * Test for FAILURE
         */
        $fetched = $this->getBuild($appId, $buildId);
        $this->assertSame(404, $fetched['headers']['status-code']);
        $this->assertSame('distribution_build_not_found', $fetched['body']['type']);

        $logs = $this->listBuildLogs($appId, $buildId);
        $this->assertSame(404, $logs['headers']['status-code']);
        $this->assertSame('distribution_build_not_found', $logs['body']['type']);

        $canceled = $this->updateBuildStatus($appId, $buildId, 'canceled');
        $this->assertSame(404, $canceled['headers']['status-code']);
        $this->assertSame('distribution_build_not_found', $canceled['body']['type']);

        $deleted = $this->deleteBuild($appId, $buildId);
        $this->assertSame(404, $deleted['headers']['status-code']);
        $this->assertSame('distribution_build_not_found', $deleted['body']['type']);

        $unknownApp = 'missing-' . \uniqid();
        $listed = $this->listBuilds($unknownApp);
        $this->assertSame(404, $listed['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $listed['body']['type']);

        $foreign = $this->getBuild($unknownApp, $buildId);
        $this->assertSame(404, $foreign['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $foreign['body']['type']);
    }

    /**
     * @param array<string, mixed> $app
     * @param array<string, mixed> $target
     * @return array{appId: string, appName: string, targetId: string, identifier: string, repository: string}
     */
    protected function prepareBuild(array $app = [], array $target = []): array
    {
        $installationId = $this->createGiteaInstallation()['$id'];
        $repository = $this->createGiteaRepository(['pubspec.yaml' => self::SOURCES]);

        $created = $this->createApp([
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $repository['id'],
            'providerBranch' => self::GITEA_BRANCH,
            'providerDeployments' => false,
            ...$app,
        ]);
        $this->assertSame(201, $created['headers']['status-code'], \json_encode($created['body']));

        $identifier = 'io.appwrite.build' . \uniqid();
        $targeted = $this->createTarget($created['body']['$id'], [
            'identifier' => $identifier,
            ...$target,
        ]);
        $this->assertSame(201, $targeted['headers']['status-code'], \json_encode($targeted['body']));

        return [
            'appId' => $created['body']['$id'],
            'appName' => $created['body']['name'],
            'targetId' => $targeted['body']['$id'],
            'identifier' => $identifier,
            'repository' => $repository['name'],
        ];
    }

    protected function createAppVariable(string $appId, string $key, string $value, bool $secret = false): void
    {
        $variable = $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/variables', $this->headers(), [
            'variableId' => ID::unique(),
            'key' => $key,
            'value' => $value,
            'secret' => $secret,
        ]);
        $this->assertSame(201, $variable['headers']['status-code'], \json_encode($variable['body']));
    }

    protected function branchHead(string $repository): string
    {
        $branch = $this->giteaApi(Client::METHOD_GET, '/api/v1/repos/' . self::GITEA_USERNAME . '/' . $repository . '/branches/' . self::GITEA_BRANCH);
        $this->assertSame(200, $branch['headers']['status-code'], \json_encode($branch['body']));

        return $branch['body']['commit']['id'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function commitStatuses(string $repository, string $commit): array
    {
        $statuses = $this->giteaApi(Client::METHOD_GET, '/api/v1/repos/' . self::GITEA_USERNAME . '/' . $repository . '/commits/' . $commit . '/statuses');
        $this->assertSame(200, $statuses['headers']['status-code'], \json_encode($statuses['body']));

        return $statuses['body'];
    }

    /**
     * The states of the app's commit check on a commit, oldest first.
     *
     * @return list<string>
     */
    protected function commitStates(string $repository, string $commit, string $appName): array
    {
        $statuses = \array_values(\array_filter(
            $this->commitStatuses($repository, $commit),
            fn (array $status): bool => \str_starts_with((string) ($status['context'] ?? ''), $appName),
        ));
        \usort($statuses, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return \array_column($statuses, 'status');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function webhookDeliveries(string $webhookId, string $event): array
    {
        $response = @\file_get_contents('http://request-catcher-webhook:5000/__find_request__?' . \http_build_query([
            'header_X-Appwrite-Webhook-Id' => $webhookId,
        ]));
        $requests = \is_string($response) ? \json_decode($response, true) : [];

        return \array_values(\array_filter(
            \is_array($requests) ? $requests : [],
            fn (array $request): bool => \in_array($event, \explode(',', (string) ($request['headers']['X-Appwrite-Webhook-Events'] ?? '')), true),
        ));
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function listBuilds(string $appId, array $queries = []): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/builds', $this->headers(), [
            'queries' => $queries,
        ]);
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    protected function listBuildLogs(string $appId, string $buildId, array $queries = []): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/builds/' . $buildId . '/logs', $this->headers(), [
            'queries' => $queries,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateBuildStatus(string $appId, string $buildId, string $status): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId . '/builds/' . $buildId . '/status', $this->headers(), [
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteBuild(string $appId, string $buildId): array
    {
        return $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId . '/builds/' . $buildId, $this->headers());
    }

    /**
     * @param array<string, int> $expected Totals keyed by metric
     */
    protected function assertUsage(string $appId, array $expected): void
    {
        $headers = $this->usageHeaders();

        $this->assertEventually(function () use ($headers, $appId, $expected): void {
            $this->assertSame($expected, $this->usageTotals($headers, $appId, \array_keys($expected)));
        }, 180_000, 2000);
    }

    /**
     * Holds past another reconcile tick, which must not meter the build again.
     *
     * @param array<string, int> $expected Totals keyed by metric
     */
    protected function assertUsageStays(string $appId, array $expected): void
    {
        $headers = $this->usageHeaders();

        $this->assertStays(
            fn (): array => $this->usageTotals($headers, $appId, \array_keys($expected)),
            $expected,
            'A build is metered once',
            self::RECONCILE_TICK,
        );
    }

    /**
     * @return array<string, string>
     */
    private function usageHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getNewKey(['usage.read']),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param list<string> $metrics
     * @return array<string, int>
     */
    private function usageTotals(array $headers, string $appId, array $metrics): array
    {
        $usage = $this->client->call(Client::METHOD_GET, '/usage/events', $headers, [
            'metrics' => $metrics,
            'queries' => [Query::equal('resourceId', [$appId])->toString()],
        ]);
        $this->assertSame(200, $usage['headers']['status-code']);

        $totals = \array_fill_keys($metrics, 0);
        foreach ($usage['body']['metrics'] as $metric) {
            if (\array_key_exists($metric['metric'], $totals)) {
                $totals[$metric['metric']] += (int) \array_sum(\array_column($metric['points'], 'value'));
            }
        }

        return $totals;
    }
}
