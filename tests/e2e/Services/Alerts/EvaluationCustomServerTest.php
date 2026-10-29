<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Alerts;

use Swoole\Coroutine;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Console\Command;
use Utopia\Console\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;

use function Swoole\Coroutine\run;

/**
 * Drives monitors through the real scheduler, usage pipeline, evaluator and
 * notifications worker. The stack evaluates every `_APP_ALERTS_INTERVAL`
 * seconds, which the test environment keeps short.
 */
final class EvaluationCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private const int TIMEOUT_MS = 180_000;

    public function testMonitorFiresAndResolvesItsAlertThroughEveryChannel(): void
    {
        $name = 'Egress ' . \uniqid();
        $path = '/alerts/' . \uniqid();

        // Traffic for the project, so bandwidth.outbound has something to sum.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/monitors', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => $name,
            'rules' => [Query::greaterThan('bandwidth.outbound', 0)->toString()],
            'window' => 3600,
            'cooldown' => 0,
            'channels' => ['webhook', 'console', 'email'],
            'webhookUrl' => 'http://request-catcher-webhook:5000' . $path,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];
        $secret = $created['body']['webhookSecret'];

        /**
         * Test for SUCCESS
         */
        $firing = $this->waitForStatus($monitorId, 'firing');
        $this->assertGreaterThan(0, $firing['value']);
        $this->assertNotNull($firing['triggeredAt']);
        $this->assertNotNull($firing['evaluatedAt']);
        $this->assertNotSame('', $firing['alertId']);

        $alert = $this->getAlert($firing['alertId']);
        $this->assertSame('firing', $alert['status']);
        $this->assertSame($monitorId, $alert['monitorId']);
        $this->assertSame($name, $alert['name']);
        $this->assertSame('usage', $alert['type']);
        $this->assertSame('project', $alert['resourceType']);
        $this->assertSame('bandwidth.outbound', $alert['metric']);
        $this->assertSame('greaterThan', $alert['comparator']);
        $this->assertGreaterThan(0, $alert['value']);
        $this->assertGreaterThanOrEqual($alert['value'], $alert['peak']);
        $this->assertNull($alert['resolvedAt']);
        $this->assertSame([$firing['alertId']], \array_column($this->listAlerts([
            Query::equal('monitorId', [$monitorId])->toString(),
            Query::equal('status', ['firing'])->toString(),
        ])['alerts'], '$id'));

        $webhook = $this->waitForWebhook($path, 'firing');
        $timestamp = $webhook['headers']['X-Appwrite-Webhook-Timestamp'];
        $this->assertSame(
            'sha256=' . \hash_hmac('sha256', $timestamp . '.' . $webhook['body'], $secret),
            $webhook['headers']['X-Appwrite-Webhook-Signature'],
        );
        $this->assertStringStartsWith('[Firing] ' . $name . ' · ', $webhook['data']['subject']);
        $this->assertSame(['alerts.firing'], $webhook['data']['events']);
        $this->assertSame($monitorId, $webhook['data']['params']['monitorId']);
        $this->assertSame($firing['alertId'], $webhook['data']['params']['alertId']);
        $this->assertSame('usage', $webhook['data']['params']['type']);
        $this->assertSame('firing', $webhook['data']['params']['status']);
        $this->assertSame('bandwidth.outbound', $webhook['data']['params']['metric']);
        $this->assertSame($this->getProject()['$id'], $webhook['data']['params']['projectId']);
        $this->assertGreaterThan(0, $webhook['data']['params']['value']);

        // The project sends its own mail through custom SMTP; an alert is the
        // platform writing to the organization's owners, so it uses the platform mailer.
        $email = $this->getLastEmailByAddress($this->getRoot()['email'], function (array $email) use ($name) {
            $this->assertStringStartsWith('[Firing] ' . $name . ' · ', $email['subject']);
        });
        $this->assertSame(System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', 'team@localhost.test'), $email['from'][0]['address']);
        $this->assertStringContainsString($name, (string) $email['html']);
        // Values read in the metric's unit, and the button opens the alert itself.
        $this->assertStringContainsString('Outbound bandwidth is above 0 B', (string) $email['html']);
        $this->assertStringContainsString('/projects/' . $this->getProject()['$id'] . '/usage/alerts?alert=' . $firing['alertId'], (string) $email['html']);
        // "Pause this rule" opens the monitor, whose drawer pauses it.
        $this->assertStringContainsString('/projects/' . $this->getProject()['$id'] . '/usage/monitors?monitor=' . $monitorId, (string) $email['html']);

        $bell = $this->client->call(Client::METHOD_GET, '/notifications', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'queries' => [Query::equal('projectId', [$this->getProject()['$id']])->toString()],
        ]);
        $this->assertSame(200, $bell['headers']['status-code']);
        $this->assertNotEmpty(\array_filter(\array_column($bell['body']['notifications'], 'title'), fn (string $title) => \str_starts_with($title, '[Firing] ' . $name . ' · ')));

        // Raising the threshold above any traffic the test made resolves the alert.
        $this->setThreshold($monitorId, 1e15);

        $resolved = $this->waitForStatus($monitorId, 'ok');
        $this->assertSame($firing['triggeredAt'], $resolved['triggeredAt']);
        $this->assertSame('', $resolved['alertId']);

        $alert = $this->getAlert($firing['alertId']);
        $this->assertSame('resolved', $alert['status']);
        $this->assertNotNull($alert['resolvedAt']);
        $this->assertSame(1, $alert['notifications']);
        $this->assertSame([], $this->listAlerts([
            Query::equal('monitorId', [$monitorId])->toString(),
            Query::equal('status', ['firing'])->toString(),
        ])['alerts']);

        $webhook = $this->waitForWebhook($path, 'resolved');
        $this->assertStringStartsWith('[Resolved] ' . $name . ' · ', $webhook['data']['subject']);
        $this->assertSame(['alerts.resolved'], $webhook['data']['events']);
        $this->assertSame($firing['alertId'], $webhook['data']['params']['alertId']);
    }

    public function testEachFiringOpensAnAlertThatOutlivesItsMonitor(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/monitors', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => 'History ' . \uniqid(),
            'rules' => [Query::greaterThan('bandwidth.outbound', 0)->toString()],
            'cooldown' => 0,
            'channels' => [],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $first = $this->waitForStatus($monitorId, 'firing')['alertId'];
        $this->setThreshold($monitorId, 1e15);
        $this->waitForStatus($monitorId, 'ok');
        $this->setThreshold($monitorId, 0);
        $second = $this->waitForStatus($monitorId, 'firing')['alertId'];

        // A new firing is a new alert; the first one stays resolved.
        $this->assertNotSame($first, $second);
        $this->assertSame('resolved', $this->getAlert($first)['status']);

        $alerts = $this->listAlerts([
            Query::equal('monitorId', [$monitorId])->toString(),
            Query::orderAsc('$createdAt')->toString(),
        ]);
        $this->assertSame(2, $alerts['total']);
        $this->assertSame([$first, $second], \array_column($alerts['alerts'], '$id'));
        $this->assertSame(['resolved', 'firing'], \array_column($alerts['alerts'], 'status'));

        $resolved = $this->listAlerts([
            Query::equal('monitorId', [$monitorId])->toString(),
            Query::equal('status', ['resolved'])->toString(),
        ]);
        $this->assertSame([$first], \array_column($resolved['alerts'], '$id'));

        // Deleting the monitor resolves the alert it was firing and keeps its history.
        $deleted = $this->client->call(Client::METHOD_DELETE, '/monitors/' . $monitorId, $this->headers());
        $this->assertSame(204, $deleted['headers']['status-code']);

        $kept = $this->listAlerts([Query::equal('monitorId', [$monitorId])->toString()]);
        $this->assertSame(2, $kept['total']);
        $this->assertSame(['resolved', 'resolved'], \array_column($kept['alerts'], 'status'));
        $this->assertNotNull($this->getAlert($second)['resolvedAt']);

        // Maintenance removes resolved alerts once they are older than the retention.
        $retention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_ALERTS', '7776000');
        $this->withFactory(function (object $factory) use ($first, $retention): void {
            $factory->platform()->updateDocument('monitorAlerts', $first, new Document([
                'resolvedAt' => DateTime::addSeconds(new \DateTime(), -$retention - 3600),
            ]));
        });

        $stdout = '';
        $stderr = '';
        $code = Console::execute((new Command('docker'))->argument('exec')->argument('appwrite')->argument('maintenance')->argument('--type=trigger'), '', $stdout, $stderr);
        $this->assertSame(0, $code, "Maintenance command failed with code {$code}: {$stderr} ({$stdout})");

        $this->assertEventually(function () use ($first) {
            $gone = $this->client->call(Client::METHOD_GET, '/alerts/' . $first, $this->headers());
            $this->assertSame(404, $gone['headers']['status-code']);
        }, 30_000, 1_000);
        $this->assertSame($second, $this->getAlert($second)['$id']);

        /**
         * Test for FAILURE
         */
        $missing = $this->client->call(Client::METHOD_GET, '/alerts/missing', $this->headers());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('alert_not_found', $missing['body']['type']);

        $other = $this->getProject(true);
        $foreign = $this->client->call(Client::METHOD_GET, '/alerts/' . $first, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $other['$id'],
            'x-appwrite-key' => $other['apiKey'],
        ]);
        $this->assertSame(404, $foreign['headers']['status-code']);
    }

    public function testCooldownSuppressesRefiringAndItsResolution(): void
    {
        $path = '/alerts/' . \uniqid();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/monitors', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => 'Flapping ' . \uniqid(),
            'rules' => [Query::greaterThan('bandwidth.outbound', 0)->toString()],
            'window' => 3600,
            'cooldown' => 3600,
            'channels' => ['webhook'],
            'webhookUrl' => 'http://request-catcher-webhook:5000' . $path,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        // First firing and its resolution are announced.
        $this->waitForStatus($monitorId, 'firing');
        $this->waitForWebhook($path, 'firing');
        $this->setThreshold($monitorId, 1e15);
        $this->waitForStatus($monitorId, 'ok');
        $this->waitForWebhook($path, 'resolved');

        // Firing again inside the cooldown is recorded but not announced, and so is its resolution.
        $this->setThreshold($monitorId, 0);
        $refiring = $this->waitForStatus($monitorId, 'firing');
        $this->setThreshold($monitorId, 1e15);
        $resolved = $this->waitForStatus($monitorId, 'ok');
        $this->assertSame($refiring['triggeredAt'], $resolved['triggeredAt']);

        // Give the notifications worker time to deliver anything that was queued.
        \sleep(10);
        $this->assertSame(['firing', 'resolved'], $this->webhookStatuses($path));
    }

    public function testFiringThatOutlastsItsCooldownIsAnnounced(): void
    {
        $path = '/alerts/' . \uniqid();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/monitors', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => 'Sustained ' . \uniqid(),
            'rules' => [Query::greaterThan('bandwidth.outbound', 0)->toString()],
            'window' => 3600,
            'cooldown' => 60,
            'channels' => ['webhook'],
            'webhookUrl' => 'http://request-catcher-webhook:5000' . $path,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $first = $this->waitForStatus($monitorId, 'firing');
        $this->waitForWebhook($path, 'firing');
        $this->setThreshold($monitorId, 1e15);
        $this->waitForStatus($monitorId, 'ok');
        $this->waitForWebhook($path, 'resolved');

        // Firing again inside the cooldown is held back...
        $this->setThreshold($monitorId, 0);
        $held = $this->waitForStatus($monitorId, 'firing');
        $this->assertSame($first['triggeredAt'], $held['triggeredAt']);
        $this->assertSame(['firing', 'resolved'], $this->webhookStatuses($path));

        // ...and announced by the first evaluation after the cooldown, while it still fires.
        $this->assertEventually(function () use ($monitorId, $first) {
            $monitor = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body'];
            $this->assertSame('firing', $monitor['status']);
            $this->assertNotSame($first['triggeredAt'], $monitor['triggeredAt']);
            $this->assertGreaterThanOrEqual(60, \strtotime($monitor['triggeredAt']) - \strtotime($first['triggeredAt']));
        }, self::TIMEOUT_MS, 2_000);
        $this->assertEventually(function () use ($path) {
            $this->assertSame(['firing', 'resolved', 'firing'], $this->webhookStatuses($path));
        }, 60_000, 1_000);
    }

    public function testPausedMonitorIsNotEvaluatedUntilResumed(): void
    {
        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => 'Paused ' . \uniqid(),
            'rules' => [Query::greaterThan('bandwidth.outbound', 1e15)->toString()],
            'channels' => ['console'],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $evaluated = $this->waitForEvaluation($monitorId, null);

        $paused = $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $this->headers(), ['enabled' => false]);
        $this->assertSame(200, $paused['headers']['status-code']);
        // Let an evaluation already queued before the pause finish first.
        \sleep(2);
        $before = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body']['evaluatedAt'];

        // Several evaluation intervals pass without the paused monitor being checked.
        \sleep(20);
        $monitor = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body'];
        $this->assertSame($before, $monitor['evaluatedAt']);

        $resumed = $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $this->headers(), ['enabled' => true]);
        $this->assertSame(200, $resumed['headers']['status-code']);
        $this->assertNotSame($evaluated, $this->waitForEvaluation($monitorId, $monitor['evaluatedAt']));
    }

    public function testDocumentsDBReadsReachTheirDatabaseMonitor(): void
    {
        // DocumentsDB reports reads as documentsdb.databases.operations.reads, so
        // a monitor on a DocumentsDB database must read that series, not the TablesDB one.
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', $this->headers(), [
            'databaseId' => 'unique()',
            'name' => 'Catalog',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', $this->headers(), [
            'collectionId' => 'unique()',
            'name' => 'Products',
            'documentSecurity' => false,
            'permissions' => [],
        ]);
        $this->assertSame(201, $collection['headers']['status-code']);
        $documents = '/documentsdb/' . $databaseId . '/collections/' . $collection['body']['$id'] . '/documents';

        $document = $this->client->call(Client::METHOD_POST, $documents, $this->headers(), [
            'documentId' => 'unique()',
            'data' => ['sku' => 'A1'],
        ]);
        $this->assertSame(201, $document['headers']['status-code']);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, $documents . '/' . $document['body']['$id'], $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => 'Catalog reads',
            'rules' => [
                Query::greaterThan('databases.reads', 0)->toString(),
                Query::equal('resourceType', ['database'])->toString(),
                Query::equal('resourceId', [$databaseId])->toString(),
            ],
            'channels' => [],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $firing = $this->waitForStatus($created['body']['$id'], 'firing');
        $this->assertGreaterThanOrEqual(3, $firing['value']);
    }

    /**
     * @return array<string, string>
     */
    /**
     * @param callable(object): void $callback receives the registered `databaseFactory`, whose class an edition may replace
     */
    private function withFactory(callable $callback): void
    {
        $run = function () use ($callback): void {
            global $register;
            $authorization = new Authorization();
            $factory = $register->get('databaseFactory')($register->get('pools'), $register->get('cache'), $authorization);

            $authorization->skip(fn () => $callback($factory));
        };

        if (Coroutine::getCid() >= 0) {
            $run();
        } else {
            run($run);
        }
    }

    private function headers(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    /**
     * @return array<string, mixed>
     */
    private function getAlert(string $alertId): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/alerts/' . $alertId, $this->headers());
        $this->assertSame(200, $response['headers']['status-code']);

        return $response['body'];
    }

    /**
     * @param array<string> $queries
     * @return array<string, mixed>
     */
    private function listAlerts(array $queries): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/alerts', $this->headers(), ['queries' => $queries]);
        $this->assertSame(200, $response['headers']['status-code']);

        return $response['body'];
    }

    private function setThreshold(string $monitorId, float|int $threshold): void
    {
        $response = $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $this->headers(), [
            'rules' => [Query::greaterThan('bandwidth.outbound', $threshold)->toString()],
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForStatus(string $monitorId, string $status): array
    {
        $monitor = [];
        $this->assertEventually(function () use ($monitorId, $status, &$monitor) {
            $response = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers());
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame($status, $response['body']['status'], 'Monitor ' . $monitorId . ' last evaluated as ' . \json_encode($response['body']));
            $monitor = $response['body'];
        }, self::TIMEOUT_MS, 2_000);

        return $monitor;
    }

    /**
     * Waits for the monitor to be evaluated after `$after` (any evaluation when null).
     */
    private function waitForEvaluation(string $monitorId, ?string $after): string
    {
        $evaluatedAt = '';
        $this->assertEventually(function () use ($monitorId, $after, &$evaluatedAt) {
            $monitor = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body'];
            $this->assertNotNull($monitor['evaluatedAt']);
            $this->assertNotSame($after, $monitor['evaluatedAt']);
            $evaluatedAt = $monitor['evaluatedAt'];
        }, self::TIMEOUT_MS, 1_000);

        return $evaluatedAt;
    }

    /**
     * Statuses of every webhook delivered to `$path`, oldest first.
     *
     * @return list<string|null>
     */
    private function webhookStatuses(string $path): array
    {
        return \array_map(
            fn (array $request) => \json_decode($request['data'] ?? '{}', true)['params']['status'] ?? null,
            \json_decode((string) \file_get_contents('http://request-catcher-webhook:5000/__find_request__?url=' . \urlencode($path)), true) ?: [],
        );
    }

    /**
     * @return array<string, mixed> the request the catcher recorded, with its raw `body` and decoded `data`
     */
    private function waitForWebhook(string $path, string $status): array
    {
        $request = [];
        $this->assertEventually(function () use ($path, $status, &$request) {
            $request = $this->getLastRequestForProject(
                $this->getProject()['$id'],
                self::REQUEST_TYPE_WEBHOOK,
                ['url' => $path],
                0,
                0,
                function (array $request) use ($status) {
                    $this->assertSame($status, $request['data']['params']['status'] ?? null);
                },
            );
            $this->assertNotEmpty($request, "No '{$status}' webhook reached {$path}");
        }, 60_000, 1_000);

        return $request;
    }
}
