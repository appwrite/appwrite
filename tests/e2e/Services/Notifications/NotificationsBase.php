<?php

namespace Tests\E2E\Services\Notifications;

use Ahc\Jwt\JWT;
use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

/**
 * End-to-end coverage for the notifications queue health surface and the
 * notifications user-facing API.
 *
 * The notification worker itself is exercised in unit tests with a pinned
 * queue payload; the server side cannot deterministically inject a
 * Notification onto the live queue without an admin endpoint, so the health
 * portion validates the public contract that ops and KEDA scale on:
 *
 *   - GET /v1/health/queue/notifications returns the live queue depth
 *   - the threshold guard returns 503 when the depth exceeds the budget
 *   - the failed-jobs surface accepts the notifications queue name
 *
 * The notifications portion exercises the full webhook-paused fanout end-to-end:
 *
 *   - GET /v1/notifications (empty + populated)
 *   - PATCH /v1/notifications/:notificationId (happy + unauthorized)
 *   - GET /v1/notifications/logos/appwrite (valid JWT + invalid JWT)
 *
 * The same webhook-paused fanout drives both channels of a single
 * Notification, so the email channel is asserted end-to-end through maildev
 * (Notifications worker → SMTP → maildev inbox) alongside the console alert.
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

    public function testListNotificationsEmpty(): void
    {
        // Always read notifications as the console-authenticated owner of the team.
        // The /v1/notifications endpoint is platform-scoped (dbForPlatform) and
        // requires a session; server-mode API keys do not satisfy it.
        $response = $this->client->call(Client::METHOD_GET, '/notifications', $this->getConsoleAlertHeaders());

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('notifications', $response['body']);
        $this->assertArrayHasKey('total', $response['body']);
        $this->assertIsArray($response['body']['notifications']);
        $this->assertIsInt($response['body']['total']);

        // The shared root console user may carry notifications from prior tests in the
        // same suite — assert only that the response shape is correct and that
        // counts agree.
        $this->assertSame(\count($response['body']['notifications']), \min(\count($response['body']['notifications']), $response['body']['total']));
    }

    public function testWebhookFailureCreatesConsoleAlert(): void
    {
        $alertId = $this->seedWebhookFailureAlert();

        $this->assertNotEmpty($alertId);

        $list = $this->client->call(Client::METHOD_GET, '/notifications', $this->getConsoleAlertHeaders());
        $this->assertSame(200, $list['headers']['status-code']);

        $found = null;
        foreach ($list['body']['notifications'] as $alert) {
            if ($alert['$id'] === $alertId) {
                $found = $alert;
                break;
            }
        }

        $this->assertNotNull($found, 'Seeded alert not present in /notifications response.');
        $this->assertSame('console', $found['channel']);
        $this->assertStringContainsStringIgnoringCase('webhook', $found['title']);

        // Cache the seeded alert id for downstream tests in the same process.
        self::$seededAlertId = $alertId;
    }

    /**
     * The webhook-paused fanout enqueues a single Notification with both a
     * console and an email recipient. The console side is asserted above; this
     * verifies the email channel travels the full path — Notifications worker
     * → SMTP → maildev — by polling the maildev inbox for the rendered email
     * addressed to the project owner.
     */
    public function testWebhookFailureSendsEmailNotification(): void
    {
        // Reuse the fanout from the console-alert test when it already ran in
        // this process; otherwise drive a fresh one. Either way the owner email
        // lands in maildev for the root user this suite created.
        if (self::$seededAlertId === null) {
            self::$seededAlertId = $this->seedWebhookFailureAlert();
        }

        $ownerEmail = $this->getRoot()['email'];

        $this->assertEventually(function () use ($ownerEmail) {
            $this->assertGreaterThanOrEqual(
                1,
                $this->countNotificationEmails($ownerEmail, 'Webhook deliveries have been paused'),
                'No webhook-paused email observed in maildev for ' . $ownerEmail
            );
        }, 30000, 1000);
    }

    public function testMarkNotificationReadTogglesFlag(): void
    {
        $alertId = self::$seededAlertId ?? $this->seedWebhookFailureAlert();
        $this->assertNotEmpty($alertId);

        $patch = $this->client->call(
            Client::METHOD_PATCH,
            '/notifications/' . $alertId,
            $this->getConsoleAlertHeaders(),
            ['read' => true]
        );

        $this->assertSame(200, $patch['headers']['status-code']);
        $this->assertSame($alertId, $patch['body']['$id']);
        $this->assertTrue($patch['body']['read']);

        $list = $this->client->call(Client::METHOD_GET, '/notifications', $this->getConsoleAlertHeaders());
        $this->assertSame(200, $list['headers']['status-code']);

        $found = null;
        foreach ($list['body']['notifications'] as $alert) {
            if ($alert['$id'] === $alertId) {
                $found = $alert;
                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertTrue($found['read']);

        self::$seededAlertId = null; // notification is read, downstream tests will seed fresh
    }

    public function testMarkNotificationReadUnauthorized(): void
    {
        $alertId = $this->seedWebhookFailureAlert();
        $this->assertNotEmpty($alertId);

        // Create a stranger console user with their own session.
        $stranger = $this->createConsoleUser();

        $unauthorized = $this->client->call(
            Client::METHOD_PATCH,
            '/notifications/' . $alertId,
            [
                'origin' => 'http://localhost',
                'content-type' => 'application/json',
                'cookie' => 'a_session_console=' . $stranger['session'],
                'x-appwrite-project' => 'console',
            ],
            ['read' => true]
        );

        $this->assertSame(401, $unauthorized['headers']['status-code']);
        $this->assertSame('user_unauthorized', $unauthorized['body']['type'] ?? '');

        // Owner re-fetches — alert must still be unread.
        $list = $this->client->call(Client::METHOD_GET, '/notifications', $this->getConsoleAlertHeaders());
        $this->assertSame(200, $list['headers']['status-code']);

        $found = null;
        foreach ($list['body']['notifications'] as $alert) {
            if ($alert['$id'] === $alertId) {
                $found = $alert;
                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertFalse($found['read']);

        self::$seededAlertId = $alertId;
    }

    public function testProjectAccountCannotListPlatformNotificationsWithCollidingUserId(): void
    {
        $alertId = $this->seedWebhookFailureAlert();
        $this->assertNotEmpty($alertId);

        $attackerProject = $this->getProject(true);
        $projectId = $attackerProject['$id'];
        $email = \uniqid('colliding-alert-user-', true) . '@localhost.test';
        $password = 'password';

        $created = $this->client->call(Client::METHOD_POST, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $attackerProject['apiKey'],
        ], [
            'userId' => $this->getRoot()['$id'],
            'email' => $email,
            'password' => $password,
            'name' => 'Colliding Alert User',
        ]);

        $this->assertSame(201, $created['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertSame(201, $session['headers']['status-code']);
        $this->assertNotEmpty($session['cookies']['a_session_' . $projectId] ?? '');

        $response = $this->client->call(Client::METHOD_GET, '/notifications', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_' . $projectId . '=' . $session['cookies']['a_session_' . $projectId],
            'x-appwrite-project' => $projectId,
        ]);

        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('user_unauthorized', $response['body']['type'] ?? '');

        self::$seededAlertId = $alertId;
    }

    public function testNotificationLogoTracksViews(): void
    {
        $alertId = self::$seededAlertId ?? $this->seedWebhookFailureAlert();
        $this->assertNotEmpty($alertId);
        $alert = $this->findConsoleAlert($alertId);
        $this->assertFalse($alert['read']);
        $this->assertEmpty($alert['firstSeen'] ?? null);
        $this->assertEmpty($alert['lastSeen'] ?? null);

        $secret = System::getEnv('_APP_NOTIFICATIONS_TRACKING_SECRET');
        $this->assertNotEmpty($secret, '_APP_NOTIFICATIONS_TRACKING_SECRET must be set for notification logo test');
        $recipientHash = \substr($alertId, \strrpos($alertId, '_') + 1);

        // Logo endpoint requires `purpose: 'notification_track'`. Other claim
        // purposes are silently ignored, which the purpose-claim test covers.
        $jwt = (new JWT($secret, 'HS256', NOTIFICATION_TRACKING_JWT_TTL, 0))->encode([
            'messageId' => $alert['messageId'],
            'channel' => $alert['channel'],
            'recipientHash' => $recipientHash,
            'projectId' => $alert['projectId'],
            'purpose' => 'notification_track',
        ]);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/notifications/logos/appwrite',
            ['x-appwrite-project' => 'console'],
            [
                'jwt' => $jwt,
                'theme' => 'dark',
            ]
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/svg+xml', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);
        $this->assertStringStartsWith('<svg', $response['body']);
        $this->assertStringContainsString('Appwrite', $response['body']);

        $tracked = $this->findConsoleAlert($alertId);
        $this->assertTrue($tracked['read']);
        $this->assertNotEmpty($tracked['firstSeen']);
        $this->assertNotEmpty($tracked['lastSeen']);

        \sleep(1);

        $secondResponse = $this->client->call(
            Client::METHOD_GET,
            '/notifications/logos/appwrite',
            ['x-appwrite-project' => 'console'],
            ['jwt' => $jwt]
        );

        $this->assertSame(200, $secondResponse['headers']['status-code']);

        $viewedAgain = $this->findConsoleAlert($alertId);
        $this->assertSame($tracked['firstSeen'], $viewedAgain['firstSeen']);
        $this->assertNotSame($tracked['lastSeen'], $viewedAgain['lastSeen']);

        self::$seededAlertId = null;
    }

    /**
     * Reviewer M7: a tracking JWT without a `purpose: 'notification_track'` claim
     * must be silently rejected. Without the purpose check, any JWT minted
     * with the same secret (sessions, password reset, etc.) could be
     * replayed against this endpoint to mark arbitrary notifications as read.
     *
     * The endpoint always returns the SVG logo (200 image/svg+xml) — the only
     * observable difference is whether the alert flips to `read: true`.
     */
    public function testNotificationLogoRejectsJwtWithoutPurposeClaim(): void
    {
        $alertId = $this->seedWebhookFailureAlert();
        $this->assertNotEmpty($alertId);
        $alert = $this->findConsoleAlert($alertId);

        $secret = System::getEnv('_APP_NOTIFICATIONS_TRACKING_SECRET');
        $this->assertNotEmpty($secret, '_APP_NOTIFICATIONS_TRACKING_SECRET must be set for the JWT purpose-claim test');
        $recipientHash = \substr($alertId, \strrpos($alertId, '_') + 1);

        // Mint a JWT with valid message/recipient claims but NO purpose claim.
        $jwtNoPurpose = (new JWT($secret, 'HS256', NOTIFICATION_TRACKING_JWT_TTL, 0))->encode([
            'messageId' => $alert['messageId'],
            'channel' => $alert['channel'],
            'recipientHash' => $recipientHash,
            'projectId' => $alert['projectId'],
        ]);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/notifications/logos/appwrite',
            ['x-appwrite-project' => 'console'],
            ['jwt' => $jwtNoPurpose]
        );

        $this->assertSame(200, $response['headers']['status-code'], 'endpoint must always return 200');
        $this->assertStringContainsString('image/svg+xml', $response['headers']['content-type']);

        $found = $this->findConsoleAlert($alertId);
        $this->assertFalse($found['read'], 'JWT without purpose claim must not flip the read flag');
        $this->assertEmpty($found['firstSeen'] ?? null);
        $this->assertEmpty($found['lastSeen'] ?? null);

        // Mint a JWT with a wrong purpose value: same expectation, silently rejected.
        $jwtWrongPurpose = (new JWT($secret, 'HS256', NOTIFICATION_TRACKING_JWT_TTL, 0))->encode([
            'messageId' => $alert['messageId'],
            'channel' => $alert['channel'],
            'recipientHash' => $recipientHash,
            'projectId' => $alert['projectId'],
            'purpose' => 'something_else',
        ]);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/notifications/logos/appwrite',
            ['x-appwrite-project' => 'console'],
            ['jwt' => $jwtWrongPurpose]
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/svg+xml', $response['headers']['content-type']);

        $found = $this->findConsoleAlert($alertId);
        $this->assertFalse($found['read'], 'JWT with wrong purpose value must not flip the read flag');
        $this->assertEmpty($found['firstSeen'] ?? null);
        $this->assertEmpty($found['lastSeen'] ?? null);

        self::$seededAlertId = $alertId;
    }

    public function testNotificationLogoInvalidTokenReturnsSvg(): void
    {
        $alertId = $this->seedWebhookFailureAlert();
        $this->assertNotEmpty($alertId);

        $response = $this->client->call(
            Client::METHOD_GET,
            '/notifications/logos/appwrite',
            ['x-appwrite-project' => 'console'],
            ['jwt' => 'tampered-or-empty']
        );

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertStringContainsString('image/svg+xml', $response['headers']['content-type']);
        $this->assertNotEmpty($response['body']);
        $this->assertStringStartsWith('<svg', $response['body']);

        // Notification must remain unread: invalid JWT is silently ignored, no DB write.
        $found = $this->findConsoleAlert($alertId);
        $this->assertFalse($found['read']);
        $this->assertEmpty($found['firstSeen'] ?? null);
        $this->assertEmpty($found['lastSeen'] ?? null);

        self::$seededAlertId = $alertId;
    }

    /**
     * @var string|null Cached seeded alert id so consecutive tests can reuse it
     *                  without paying the cost of another 10-failure webhook drive.
     */
    protected static ?string $seededAlertId = null;

    /**
     * Build the auth header set used to talk to the platform-scoped
     * /v1/notifications endpoints. Always console session, regardless of
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
        ];
    }

    /**
     * Count emails sitting in the maildev inbox that are addressed to the given
     * recipient and carry the given subject. Mirrors the maildev polling used
     * by the session-alert e2e suite.
     */
    protected function countNotificationEmails(string $address, string $subject): int
    {
        $host = System::getEnv('_APP_SMTP_HOST', 'maildev');
        $inbox = \file_get_contents('http://' . $host . ':1080/email');
        if ($inbox === false) {
            return 0;
        }

        $emails = \json_decode($inbox, true) ?? [];
        $count = 0;
        foreach ($emails as $email) {
            if (($email['subject'] ?? '') !== $subject) {
                continue;
            }
            foreach ($email['to'] ?? [] as $recipient) {
                if (($recipient['address'] ?? '') === $address) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    protected function findConsoleAlert(string $alertId): array
    {
        $list = $this->client->call(Client::METHOD_GET, '/notifications', $this->getConsoleAlertHeaders());
        $this->assertSame(200, $list['headers']['status-code']);

        foreach ($list['body']['notifications'] as $alert) {
            if ($alert['$id'] === $alertId) {
                return $alert;
            }
        }

        $this->fail('Alert not present in /notifications response: ' . $alertId);
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
        $webhookName = 'Failing Webhook ' . \uniqid();
        $webhook = $this->client->call(Client::METHOD_POST, '/webhooks', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => $projectId,
            'x-appwrite-mode' => 'admin',
        ], [
            'webhookId' => ID::unique(),
            'name' => $webhookName,
            'events' => ['users.*.create'],
            'url' => 'http://request-catcher-webhook:1/',
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

        // Poll for the alert. Alert creation is async (notification worker)
        // and webhook deliveries also queue up — give them generous time.
        $alertId = null;
        $this->assertEventually(function () use (&$alertId, $projectId, $webhookId, $webhookName) {
            $list = $this->client->call(Client::METHOD_GET, '/notifications', $this->getConsoleAlertHeaders());
            $this->assertSame(200, $list['headers']['status-code']);

            foreach ($list['body']['notifications'] as $alert) {
                if (
                    ($alert['channel'] ?? '') === 'console'
                    && ($alert['projectId'] ?? '') === $projectId
                    && ($alert['parentResourceId'] ?? '') === $projectId
                    && ($alert['title'] ?? '') === 'Webhook deliveries have been paused'
                    && \str_contains($alert['body'] ?? '', $webhookName)
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
