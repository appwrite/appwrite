<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Alerts;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Query;
use Utopia\System\System;

/**
 * Drives rules through the real scheduler, usage pipeline, evaluator and
 * notifications worker. The stack evaluates every `_APP_ALERTS_INTERVAL`
 * seconds, which the test environment keeps short.
 */
final class EvaluationCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    private const int TIMEOUT_MS = 180_000;

    public function testRuleFiresAndResolvesThroughEveryChannel(): void
    {
        $name = 'Egress ' . \uniqid();
        $path = '/alerts/' . \uniqid();

        // Traffic for the project, so bandwidth.outbound has something to sum.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/alerts/rules', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/alerts/rules', $this->headers(), [
            'ruleId' => 'unique()',
            'name' => $name,
            'metric' => 'bandwidth.outbound',
            'comparator' => 'greaterThan',
            'threshold' => 0,
            'window' => 3600,
            'cooldown' => 0,
            'channels' => ['webhook', 'console', 'email'],
            'webhookUrl' => 'http://request-catcher-webhook:5000' . $path,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $ruleId = $created['body']['$id'];
        $secret = $created['body']['webhookSecret'];

        /**
         * Test for SUCCESS
         */
        $firing = $this->waitForStatus($ruleId, 'firing');
        $this->assertGreaterThan(0, $firing['value']);
        $this->assertNotNull($firing['triggeredAt']);
        $this->assertNotNull($firing['evaluatedAt']);

        $webhook = $this->waitForWebhook($path, 'firing');
        $timestamp = $webhook['headers']['X-Appwrite-Webhook-Timestamp'];
        $this->assertSame(
            'sha256=' . \hash_hmac('sha256', $timestamp . '.' . $webhook['body'], $secret),
            $webhook['headers']['X-Appwrite-Webhook-Signature'],
        );
        $this->assertSame('Alert firing: ' . $name, $webhook['data']['subject']);
        $this->assertSame(['alerts.firing'], $webhook['data']['events']);
        $this->assertSame($ruleId, $webhook['data']['params']['ruleId']);
        $this->assertSame('firing', $webhook['data']['params']['status']);
        $this->assertSame('bandwidth.outbound', $webhook['data']['params']['metric']);
        $this->assertSame($this->getProject()['$id'], $webhook['data']['params']['projectId']);
        $this->assertGreaterThan(0, $webhook['data']['params']['value']);

        // The project sends its own mail through custom SMTP; an alert is the
        // platform writing to the organization's owners, so it uses the platform mailer.
        $email = $this->getLastEmailByAddress($this->getRoot()['email'], function (array $email) use ($name) {
            $this->assertSame('Alert firing: ' . $name, $email['subject']);
        });
        $this->assertSame(System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', 'team@localhost.test'), $email['from'][0]['address']);
        $this->assertStringContainsString($name, (string) $email['html']);
        // Values read in the metric's unit, and the button opens the rule itself.
        $this->assertStringContainsString('Outbound bandwidth is above 0 B', (string) $email['html']);
        $this->assertStringContainsString('/projects/' . $this->getProject()['$id'] . '/usage/alerts?rule=' . $ruleId, (string) $email['html']);

        $bell = $this->client->call(Client::METHOD_GET, '/notifications', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'queries' => [Query::equal('projectId', [$this->getProject()['$id']])->toString()],
        ]);
        $this->assertSame(200, $bell['headers']['status-code']);
        $this->assertContains('Alert firing: ' . $name, \array_column($bell['body']['notifications'], 'title'));

        // Raising the threshold above any traffic the test made resolves the rule.
        $updated = $this->client->call(Client::METHOD_PUT, '/alerts/rules/' . $ruleId, $this->headers(), [
            'threshold' => 1e15,
        ]);
        $this->assertSame(200, $updated['headers']['status-code']);

        $resolved = $this->waitForStatus($ruleId, 'ok');
        $this->assertSame($firing['triggeredAt'], $resolved['triggeredAt']);

        $webhook = $this->waitForWebhook($path, 'ok');
        $this->assertSame('Alert resolved: ' . $name, $webhook['data']['subject']);
        $this->assertSame(['alerts.ok'], $webhook['data']['events']);
    }

    public function testCooldownSuppressesRefiringAndItsResolution(): void
    {
        $path = '/alerts/' . \uniqid();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/alerts/rules', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/alerts/rules', $this->headers(), [
            'ruleId' => 'unique()',
            'name' => 'Flapping ' . \uniqid(),
            'metric' => 'bandwidth.outbound',
            'comparator' => 'greaterThan',
            'threshold' => 0,
            'window' => 3600,
            'cooldown' => 3600,
            'channels' => ['webhook'],
            'webhookUrl' => 'http://request-catcher-webhook:5000' . $path,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $ruleId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        // First firing and its resolution are announced.
        $this->waitForStatus($ruleId, 'firing');
        $this->waitForWebhook($path, 'firing');
        $this->setThreshold($ruleId, 1e15);
        $this->waitForStatus($ruleId, 'ok');
        $this->waitForWebhook($path, 'ok');

        // Firing again inside the cooldown is recorded but not announced, and so is its resolution.
        $this->setThreshold($ruleId, 0);
        $refiring = $this->waitForStatus($ruleId, 'firing');
        $this->setThreshold($ruleId, 1e15);
        $resolved = $this->waitForStatus($ruleId, 'ok');
        $this->assertSame($refiring['triggeredAt'], $resolved['triggeredAt']);

        // Give the notifications worker time to deliver anything that was queued.
        \sleep(10);
        $this->assertSame(['firing', 'ok'], $this->webhookStatuses($path));
    }

    public function testFiringThatOutlastsItsCooldownIsAnnounced(): void
    {
        $path = '/alerts/' . \uniqid();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/alerts/rules', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/alerts/rules', $this->headers(), [
            'ruleId' => 'unique()',
            'name' => 'Sustained ' . \uniqid(),
            'metric' => 'bandwidth.outbound',
            'comparator' => 'greaterThan',
            'threshold' => 0,
            'window' => 3600,
            'cooldown' => 60,
            'channels' => ['webhook'],
            'webhookUrl' => 'http://request-catcher-webhook:5000' . $path,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $ruleId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $first = $this->waitForStatus($ruleId, 'firing');
        $this->waitForWebhook($path, 'firing');
        $this->setThreshold($ruleId, 1e15);
        $this->waitForStatus($ruleId, 'ok');
        $this->waitForWebhook($path, 'ok');

        // Firing again inside the cooldown is held back...
        $this->setThreshold($ruleId, 0);
        $held = $this->waitForStatus($ruleId, 'firing');
        $this->assertSame($first['triggeredAt'], $held['triggeredAt']);
        $this->assertSame(['firing', 'ok'], $this->webhookStatuses($path));

        // ...and announced by the first evaluation after the cooldown, while it still fires.
        $this->assertEventually(function () use ($ruleId, $first) {
            $rule = $this->client->call(Client::METHOD_GET, '/alerts/rules/' . $ruleId, $this->headers())['body'];
            $this->assertSame('firing', $rule['status']);
            $this->assertNotSame($first['triggeredAt'], $rule['triggeredAt']);
            $this->assertGreaterThanOrEqual(60, \strtotime($rule['triggeredAt']) - \strtotime($first['triggeredAt']));
        }, self::TIMEOUT_MS, 2_000);
        $this->assertEventually(function () use ($path) {
            $this->assertSame(['firing', 'ok', 'firing'], $this->webhookStatuses($path));
        }, 60_000, 1_000);
    }

    public function testPausedRuleIsNotEvaluatedUntilResumed(): void
    {
        $created = $this->client->call(Client::METHOD_POST, '/alerts/rules', $this->headers(), [
            'ruleId' => 'unique()',
            'name' => 'Paused ' . \uniqid(),
            'metric' => 'bandwidth.outbound',
            'comparator' => 'greaterThan',
            'threshold' => 1e15,
            'channels' => ['console'],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $ruleId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $evaluated = $this->waitForEvaluation($ruleId, null);

        $paused = $this->client->call(Client::METHOD_PUT, '/alerts/rules/' . $ruleId, $this->headers(), ['enabled' => false]);
        $this->assertSame(200, $paused['headers']['status-code']);
        // Let an evaluation already queued before the pause finish first.
        \sleep(2);
        $before = $this->client->call(Client::METHOD_GET, '/alerts/rules/' . $ruleId, $this->headers())['body']['evaluatedAt'];

        // Several evaluation intervals pass without the paused rule being checked.
        \sleep(20);
        $rule = $this->client->call(Client::METHOD_GET, '/alerts/rules/' . $ruleId, $this->headers())['body'];
        $this->assertSame($before, $rule['evaluatedAt']);

        $resumed = $this->client->call(Client::METHOD_PUT, '/alerts/rules/' . $ruleId, $this->headers(), ['enabled' => true]);
        $this->assertSame(200, $resumed['headers']['status-code']);
        $this->assertNotSame($evaluated, $this->waitForEvaluation($ruleId, $rule['evaluatedAt']));
    }

    public function testDocumentsDBReadsReachTheirDatabaseRule(): void
    {
        // DocumentsDB reports reads as documentsdb.databases.operations.reads, so
        // a rule on a DocumentsDB database must read that series, not the TablesDB one.
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

        $created = $this->client->call(Client::METHOD_POST, '/alerts/rules', $this->headers(), [
            'ruleId' => 'unique()',
            'name' => 'Catalog reads',
            'metric' => 'databases.reads',
            'comparator' => 'greaterThan',
            'threshold' => 0,
            'resourceType' => 'database',
            'resourceId' => $databaseId,
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
    private function headers(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    private function setThreshold(string $ruleId, float $threshold): void
    {
        $response = $this->client->call(Client::METHOD_PUT, '/alerts/rules/' . $ruleId, $this->headers(), ['threshold' => $threshold]);
        $this->assertSame(200, $response['headers']['status-code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForStatus(string $ruleId, string $status): array
    {
        $rule = [];
        $this->assertEventually(function () use ($ruleId, $status, &$rule) {
            $response = $this->client->call(Client::METHOD_GET, '/alerts/rules/' . $ruleId, $this->headers());
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame($status, $response['body']['status'], 'Rule ' . $ruleId . ' last evaluated as ' . \json_encode($response['body']));
            $rule = $response['body'];
        }, self::TIMEOUT_MS, 2_000);

        return $rule;
    }

    /**
     * Waits for the rule to be evaluated after `$after` (any evaluation when null).
     */
    private function waitForEvaluation(string $ruleId, ?string $after): string
    {
        $evaluatedAt = '';
        $this->assertEventually(function () use ($ruleId, $after, &$evaluatedAt) {
            $rule = $this->client->call(Client::METHOD_GET, '/alerts/rules/' . $ruleId, $this->headers())['body'];
            $this->assertNotNull($rule['evaluatedAt']);
            $this->assertNotSame($after, $rule['evaluatedAt']);
            $evaluatedAt = $rule['evaluatedAt'];
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
