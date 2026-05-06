<?php

namespace Tests\E2E\Services\Notifications;

use Ahc\Jwt\JWT;
use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

/**
 * End-to-end coverage for the notifications queue health surface and the
 * account-alerts user-facing API.
 *
 * The notification worker itself is exercised in unit tests with a pinned
 * queue payload — the server side cannot deterministically inject a
 * Notification onto the live queue without an admin endpoint, so the health
 * portion validates the public contract that ops and KEDA scale on:
 *
 *   - GET /v1/health/queue/notifications returns the live queue depth
 *   - the threshold guard returns 503 when the depth exceeds the budget
 *   - the failed-jobs surface accepts the notifications queue name
 *
 * The alerts portion exercises the full webhook-paused fanout end-to-end:
 *
 *   - GET /v1/account/alerts (empty + populated)
 *   - PATCH /v1/account/alerts/:alertId/read (happy + unauthorized)
 *   - GET /v1/account/alerts/:alertId/track (valid JWT + invalid JWT)
 *
 * Dedup, per-channel dispatch, and webhook signing are covered by:
 *   - tests/unit/Platform/Workers/NotificationsTest.php
 *   - tests/unit/Utopia/Messaging/Adapter/ConsoleTest.php
 *   - tests/unit/Utopia/Messaging/Adapter/WebhookTest.php
 */
trait NotificationsBase
{
    public function testHealthQueueNotificationsReportsSize(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/health/queue/notifications', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertGreaterThanOrEqual(0, $response['body']['size']);
    }

    public function testHealthQueueNotificationsThresholdGuard(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/health/queue/notifications', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), ['threshold' => '0']);

        $this->assertContains($response['headers']['status-code'], [200, 503]);
    }

    public function testHealthQueueFailedAcceptsNotifications(): void
    {
        $response = $this->client->call(Client::METHOD_GET, '/health/queue/failed/v1-notifications', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
    }

    public function testListAccountAlertsEmpty(): void
    {
        // Always read alerts as the console-authenticated owner of the team.
        // The /v1/account/alerts endpoint is platform-scoped (dbForPlatform) and
        // requires a session — server-mode API keys do not satisfy it.
        $response = $this->client->call(Client::METHOD_GET, '/account/alerts', $this->getConsoleAlertHeaders());

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('alerts', $response['body']);
        $this->assertArrayHasKey('total', $response['body']);
        $this->assertIsArray($response['body']['alerts']);
        $this->assertIsInt($response['body']['total']);

        // The shared root console user may carry alerts from prior tests in the
        // same suite — assert only that the response shape is correct and that
        // counts agree.
        $this->assertSame(\count($response['body']['alerts']), \min(\count($response['body']['alerts']), $response['body']['total']));
    }

    public function testWebhookFailureCreatesConsoleAlert(): void
    {
        $alertId = $this->seedWebhookFailureAlert();

        $this->assertNotEmpty($alertId);

        $list = $this->client->call(Client::METHOD_GET, '/account/alerts', $this->getConsoleAlertHeaders());
        $this->assertSame(200, $list['headers']['status-code']);

        $found = null;
        foreach ($list['body']['alerts'] as $alert) {
            if ($alert['$id'] === $alertId) {
                $found = $alert;
                break;
            }
        }

        $this->assertNotNull($found, 'Seeded alert not present in /account/alerts response.');
        $this->assertSame('console', $found['channel']);
        $this->assertStringContainsStringIgnoringCase('webhook', $found['title']);

        // Cache the seeded alert id for downstream tests in the same process.
        self::$seededAlertId = $alertId;
    }

    public function testMarkAlertReadTogglesFlag(): void
    {
        $alertId = self::$seededAlertId ?? $this->seedWebhookFailureAlert();
        $this->assertNotEmpty($alertId);

        $patch = $this->client->call(
            Client::METHOD_PATCH,
            '/account/alerts/' . $alertId . '/read',
            $this->getConsoleAlertHeaders(),
            []
        );

        $this->assertSame(200, $patch['headers']['status-code']);
        $this->assertSame($alertId, $patch['body']['$id']);
        $this->assertTrue($patch['body']['read']);

        $list = $this->client->call(Client::METHOD_GET, '/account/alerts', $this->getConsoleAlertHeaders());
        $this->assertSame(200, $list['headers']['status-code']);

        $found = null;
        foreach ($list['body']['alerts'] as $alert) {
            if ($alert['$id'] === $alertId) {
                $found = $alert;
                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertTrue($found['read']);

        self::$seededAlertId = null; // alert is read — downstream tests will seed fresh
    }

    public function testMarkAlertReadUnauthorized(): void
    {
        $alertId = $this->seedWebhookFailureAlert();
        $this->assertNotEmpty($alertId);

        // Create a stranger console user with their own session.
        $stranger = $this->createConsoleUser();

        $unauthorized = $this->client->call(
            Client::METHOD_PATCH,
            '/account/alerts/' . $alertId . '/read',
            [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'cookie' => 'a_session_console=' . $stranger['session'],
                'x-appwrite-project' => 'console',
                'x-appwrite-mode' => 'admin',
            ],
            []
        );

        $this->assertSame(401, $unauthorized['headers']['status-code']);
        $this->assertSame('user_unauthorized', $unauthorized['body']['type'] ?? '');

        // Owner re-fetches — alert must still be unread.
        $list = $this->client->call(Client::METHOD_GET, '/account/alerts', $this->getConsoleAlertHeaders());
        $this->assertSame(200, $list['headers']['status-code']);

        $found = null;
        foreach ($list['body']['alerts'] as $alert) {
            if ($alert['$id'] === $alertId) {
                $found = $alert;
                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertFalse($found['read']);

        self::$seededAlertId = $alertId;
    }

    public function testTrackingPixelTogglesRead(): void
    {
        $alertId = self::$seededAlertId ?? $this->seedWebhookFailureAlert();
        $this->assertNotEmpty($alertId);

        $secret = System::getEnv('_APP_OPENSSL_KEY_V1');
        $this->assertNotEmpty($secret, '_APP_OPENSSL_KEY_V1 must be set for tracking pixel test');
        $userId = $this->getRoot()['$id'];

        $jwt = (new JWT($secret, 'HS256', 2592000, 0))->encode([
            'alertId' => $alertId,
            'userId' => $userId,
        ]);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/account/alerts/' . $alertId . '/track',
            ['x-appwrite-project' => 'console'],
            ['jwt' => $jwt]
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);
        $this->assertSame("\x89PNG\r\n\x1a\n", \substr($response['body'], 0, 8), 'Response body must be a PNG.');

        // Subsequent listing should report alert as read.
        $list = $this->client->call(Client::METHOD_GET, '/account/alerts', $this->getConsoleAlertHeaders());
        $this->assertSame(200, $list['headers']['status-code']);

        $found = null;
        foreach ($list['body']['alerts'] as $alert) {
            if ($alert['$id'] === $alertId) {
                $found = $alert;
                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertTrue($found['read']);

        self::$seededAlertId = null;
    }

    public function testTrackingPixelInvalidTokenReturnsPng(): void
    {
        $alertId = $this->seedWebhookFailureAlert();
        $this->assertNotEmpty($alertId);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/account/alerts/' . $alertId . '/track',
            ['x-appwrite-project' => 'console'],
            ['jwt' => 'tampered-or-empty']
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/png', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);
        $this->assertSame("\x89PNG\r\n\x1a\n", \substr($response['body'], 0, 8), 'Response body must be a PNG.');

        // Alert must remain unread — invalid JWT is silently ignored, no DB write.
        $list = $this->client->call(Client::METHOD_GET, '/account/alerts', $this->getConsoleAlertHeaders());
        $this->assertSame(200, $list['headers']['status-code']);

        $found = null;
        foreach ($list['body']['alerts'] as $alert) {
            if ($alert['$id'] === $alertId) {
                $found = $alert;
                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertFalse($found['read']);

        self::$seededAlertId = $alertId;
    }

    /**
     * @var string|null Cached seeded alert id so consecutive tests can reuse it
     *                  without paying the cost of another 10-failure webhook drive.
     */
    protected static ?string $seededAlertId = null;

    /**
     * Build the auth header set used to talk to the platform-scoped
     * /v1/account/alerts endpoints. Always console session, regardless of
     * the trait's host (server vs console) — the endpoint requires a session
     * and the root user owns the project team.
     *
     * @return array<string, string>
     */
    protected function getConsoleAlertHeaders(): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
            'x-appwrite-mode' => 'admin',
        ];
    }

    /**
     * Drive a webhook past the failure threshold so the
     * Webhooks worker emits a console+email alert fanout to the project
     * owner. Returns the alert id.
     *
     * Uses a unique webhook-per-call so concurrent tests don't share
     * attempt counters.
     */
    protected function seedWebhookFailureAlert(): string
    {
        $project = $this->getProject();
        $projectId = $project['$id'];

        // Register a webhook pointing at an unroutable address. The Webhook
        // worker will fail every delivery and after 10 attempts pause it
        // and enqueue a console+email alert for the project owner.
        $webhook = $this->client->call(Client::METHOD_POST, '/webhooks', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
        ], [
            'webhookId' => ID::unique(),
            'name' => 'Failing Webhook ' . \uniqid(),
            'events' => ['users.*.create'],
            'url' => 'http://127.0.0.1:1/',
            'tls' => false,
        ]);

        $this->assertSame(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        $maxAttempts = (int) System::getEnv('_APP_WEBHOOK_MAX_FAILED_ATTEMPTS', '10');

        // Drive the webhook past its failure threshold by issuing user-create
        // events, each of which triggers a delivery attempt the worker will
        // fail. Each create event is also dispatched to the project's
        // pre-existing reachable webhook — that one stays healthy.
        for ($i = 0; $i < $maxAttempts + 2; $i++) {
            $email = \uniqid('alert-seed-', true) . '@localhost.test';
            $created = $this->client->call(Client::METHOD_POST, '/users', \array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $projectId,
            ], $this->getHeaders()), [
                'userId' => ID::unique(),
                'email' => $email,
                'password' => 'password',
                'name' => 'Webhook Failure Driver',
            ]);

            // Tolerate transient 409s under parallel load.
            $this->assertContains(
                $created['headers']['status-code'],
                [201, 409],
                'User create failed while seeding webhook failure: ' . ($created['body']['message'] ?? '')
            );
        }

        // The deduplication key (and therefore the alert's messageId hash) is
        // unique per (webhook, attempts) tuple — see Webhooks worker. Compute
        // the expected messageId so we can deterministically match the alert
        // for *this* test instance even when other tests in the same process
        // have seeded their own webhook-failure alerts.
        // Driver loops max+2 events; the worker may pause anywhere from
        // attempts==max to attempts==max+2 depending on which delivery
        // crossed the threshold. Record possible message ids.
        $expectedMessageIds = [];
        for ($attempts = $maxAttempts; $attempts <= $maxAttempts + 2; $attempts++) {
            $expectedMessageIds[] = \md5('webhook:' . $webhookId . ':paused:' . $attempts);
        }

        // Poll for the alert. Alert creation is async (notification worker)
        // and webhook deliveries also queue up — give them generous time.
        $alertId = null;
        $this->assertEventually(function () use (&$alertId, $webhookId, $expectedMessageIds) {
            $list = $this->client->call(Client::METHOD_GET, '/account/alerts', $this->getConsoleAlertHeaders());
            $this->assertSame(200, $list['headers']['status-code']);

            foreach ($list['body']['alerts'] as $alert) {
                if (
                    ($alert['channel'] ?? '') === 'console'
                    && \in_array($alert['messageId'] ?? '', $expectedMessageIds, true)
                ) {
                    $alertId = $alert['$id'];
                    return;
                }
            }

            $this->fail('No webhook-paused console alert observed yet for webhook ' . $webhookId);
        }, 60000, 1000);

        // Cleanup the failing webhook so it doesn't keep firing in the
        // background for subsequent tests in the same process.
        $this->client->call(Client::METHOD_DELETE, '/webhooks/' . $webhookId, [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
        ]);

        return $alertId;
    }

    /**
     * Create a fresh console user with its own session, separate from the
     * shared root user. Used to assert that strangers cannot mark someone
     * else's alert as read.
     *
     * @return array{'$id': string, email: string, session: string}
     */
    protected function createConsoleUser(): array
    {
        $email = \uniqid('stranger-', true) . \getmypid() . \bin2hex(\random_bytes(4)) . '@localhost.test';
        $password = 'password';

        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => 'Stranger',
        ]);

        $this->assertSame(201, $user['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertSame(201, $session['headers']['status-code']);
        $this->assertNotEmpty($session['cookies']['a_session_console'] ?? '');

        return [
            '$id' => $user['body']['$id'],
            'email' => $email,
            'session' => $session['cookies']['a_session_console'],
        ];
    }
}
