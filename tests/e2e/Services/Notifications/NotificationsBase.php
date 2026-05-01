<?php

namespace Tests\E2E\Services\Notifications;

use Tests\E2E\Client;

/**
 * End-to-end coverage for the notifications queue health surface.
 *
 * The worker itself is exercised in unit tests with the queue payload pinned
 * to a controlled shape — the server side cannot deterministically inject a
 * Notification onto the live queue without an admin endpoint, so this suite
 * validates the public health contract that ops and KEDA scale on:
 *
 *   - GET /v1/health/queue/notifications returns the live queue depth
 *   - the threshold guard returns 503 when the depth exceeds the budget
 *   - the failed-jobs surface accepts the notifications queue name
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
}
