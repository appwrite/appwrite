<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Distribution\Store\Apple\Webhook;

final class WebhooksCustomServerTest extends Scope
{
    use DistributionBase;
    use MockStores;
    use ProjectCustom;
    use SideServer;

    private const string BUILD = '1';

    private const array EXEMPT = ['usesNonExemptEncryption' => 'false'];

    private const int DELIVERY_TIMEOUT_MS = 60_000;

    private const string DELIVERED = 'SUCCEEDED';

    private const string PING = 'WEBHOOK_PING_CREATED';

    private const string BETA_TYPE = 'buildBetaDetailExternalBuildStateUpdated';

    public function testCreateAppleWebhookRegistered(): void
    {
        $prepared = $this->prepareAppleRelease(['configuration' => self::EXEMPT]);
        $appId = $prepared['appId'];
        $this->seedAppleUpload($prepared['storeAppId'], self::BUILD, 'FAILED');
        $params = [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
        ];
        $store = $this->appleStore($prepared['key']);
        $suffix = '/v1/distribution/webhooks/apple/' . $prepared['connectionId'] . '/' . $prepared['targetId'] . '?project=' . $this->getProject()['$id'];

        $before = $store->listWebhooks($prepared['storeAppId']);
        $this->assertSame([], $before);

        $first = $this->createSubmission($appId, $params);
        $this->assertSame(201, $first['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $located = $this->awaitSubmission($appId, $first['body']['$id'], 'failed');
        $this->assertContains($located['step'], ['located', 'uploaded']);

        $webhooks = $store->listWebhooks($prepared['storeAppId']);
        $this->assertCount(1, $webhooks);
        $this->assertStringEndsWith($suffix, $webhooks[0]->url);
        $this->assertSame(Webhook::EVENTS, $webhooks[0]->events);
        $this->assertTrue($webhooks[0]->enabled);

        $second = $this->createSubmission($appId, [...$params, 'key' => 'e2e-' . \uniqid()]);
        $this->assertSame(201, $second['headers']['status-code']);
        $this->assertNotSame($first['body']['$id'], $second['body']['$id']);

        $relocated = $this->awaitSubmission($appId, $second['body']['$id'], 'failed');
        $this->assertContains($relocated['step'], ['located', 'uploaded']);

        $again = $store->listWebhooks($prepared['storeAppId']);
        $this->assertCount(1, $again);
        $this->assertSame($webhooks[0]->id, $again[0]->id);

        $deleted = $this->client->call(Client::METHOD_DELETE, '/distribution/apps/' . $appId . '/targets/' . $prepared['targetId'], $this->headers());
        $this->assertSame(204, $deleted['headers']['status-code']);
        $this->assertSame([], $store->listWebhooks($prepared['storeAppId']), 'Deleting the target deletes its App Store Connect webhook.');
    }

    public function testCreateAppleWebhookReconciles(): void
    {
        $prepared = $this->prepareAppleRelease(['configuration' => self::EXEMPT]);
        $appId = $prepared['appId'];
        $groupId = $this->seedAppleGroup($prepared['storeAppId']);

        $updated = $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId . '/targets/' . $prepared['targetId'], $this->headers(), [
            'track' => 'testflight-external',
            'configuration' => [...self::EXEMPT, 'betaGroups' => $groupId],
        ]);
        $this->assertSame(200, $updated['headers']['status-code']);

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $submissionId = $created['body']['$id'];

        $pending = $this->awaitSubmission($appId, $submissionId, 'pending');
        $this->assertSame('WAITING_FOR_BETA_REVIEW', $pending['storeStatus']);
        $this->assertNotEmpty($pending['external']['betaReviewSubmissionId']);
        $webhooks = $this->appleStore($prepared['key'])->listWebhooks($prepared['storeAppId']);
        $this->assertCount(1, $webhooks);

        /**
         * Test for SUCCESS
         */
        $this->seedAppleBetaReview($prepared['storeAppId'], self::BUILD, 'BETA_APPROVED');

        $approval = \array_values(\array_filter(
            $this->appleDeliveries($webhooks[0]->id),
            static fn (array $delivery): bool => $delivery['eventType'] === Webhook::EVENT_BETA_STATE,
        ));
        $this->assertNotEmpty($approval);
        $this->assertSame(204, \end($approval)['httpStatusCode'], 'The beta review approval event is delivered and accepted.');
        $this->assertSame(self::DELIVERED, \end($approval)['deliveryState']);

        $this->assertEventually(function () use ($appId, $submissionId): void {
            $submission = $this->getSubmission($appId, $submissionId);
            $this->assertSame(200, $submission['headers']['status-code']);
            $this->assertSame('approved', $submission['body']['status'], 'The submission is approved within the delivery timeout of the accepted webhook event.');
            $this->assertSame('BETA_APPROVED', $submission['body']['storeStatus']);
        }, self::DELIVERY_TIMEOUT_MS, 1000);
    }

    public function testCreateAppleWebhookAnswersCompliance(): void
    {
        $prepared = $this->prepareAppleRelease(['track' => 'production']);
        $appId = $prepared['appId'];

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $submissionId = $created['body']['$id'];

        $pending = $this->awaitSubmission($appId, $submissionId, 'pending');
        $this->assertSame('processed', $pending['step']);
        $this->assertSame('MISSING_EXPORT_COMPLIANCE', $pending['errorCode']);
        $this->assertArrayNotHasKey('reviewSubmissionId', $pending['external']);
        $webhooks = $this->appleStore($prepared['key'])->listWebhooks($prepared['storeAppId']);
        $this->assertCount(1, $webhooks);

        /**
         * Test for SUCCESS
         */
        $this->answerAppleCompliance($prepared['key'], $pending['external']['buildId']);

        $answer = \array_values(\array_filter(
            $this->appleDeliveries($webhooks[0]->id),
            static fn (array $delivery): bool => $delivery['eventType'] === Webhook::EVENT_BETA_STATE,
        ));
        $this->assertNotEmpty($answer);
        $this->assertSame(204, \end($answer)['httpStatusCode'], 'The export compliance answer changes the beta state, and that event is delivered and accepted.');
        $this->assertSame(self::DELIVERED, \end($answer)['deliveryState']);

        $this->assertEventually(function () use ($appId, $submissionId): void {
            $submission = $this->getSubmission($appId, $submissionId);
            $this->assertSame(200, $submission['headers']['status-code']);
            $this->assertSame('released', $submission['body']['step'], 'Answering export compliance in App Store Connect must send the version for review.');
            $this->assertSame('pending', $submission['body']['status']);
            $this->assertSame('WAITING_FOR_REVIEW', $submission['body']['storeStatus']);
            $this->assertSame('', $submission['body']['errorCode']);
            $this->assertNotEmpty($submission['body']['external']['reviewSubmissionId']);
            $this->assertNotEmpty($submission['body']['submittedAt']);
        }, self::DELIVERY_TIMEOUT_MS, 1000);
    }

    public function testCreateAppleWebhookPing(): void
    {
        $prepared = $this->prepareAppleRelease(['configuration' => self::EXEMPT]);
        $appId = $prepared['appId'];

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $submissionId = $created['body']['$id'];

        $approved = $this->awaitSubmission($appId, $submissionId, 'approved');
        $store = $this->appleStore($prepared['key']);
        $webhooks = $store->listWebhooks($prepared['storeAppId']);
        $this->assertCount(1, $webhooks);

        /**
         * Test for FAILURE
         */
        $body = ['data' => ['type' => 'webhookPingCreated', 'id' => \bin2hex(\random_bytes(16)), 'version' => 1]];
        $forged = $this->deliver($prepared['connectionId'], $prepared['targetId'], $this->getProject()['$id'], $body, Webhook::sign(\json_encode($body, JSON_THROW_ON_ERROR), 'not-the-secret'));
        $this->assertSame(401, $forged['headers']['status-code']);
        $this->assertSame('user_unauthorized', $forged['body']['type']);

        /**
         * Test for SUCCESS
         */
        $store->createWebhookPing($webhooks[0]->id);

        $deliveries = $this->appleDeliveries($webhooks[0]->id);
        $this->assertNotEmpty($deliveries);
        $ping = \end($deliveries);
        $this->assertSame(self::PING, $ping['eventType']);
        $this->assertSame(204, $ping['httpStatusCode']);
        $this->assertSame(self::DELIVERED, $ping['deliveryState']);

        $unchanged = $this->getSubmission($appId, $submissionId);
        $this->assertSame(200, $unchanged['headers']['status-code']);
        $this->assertSame('approved', $unchanged['body']['status']);
        $this->assertSame($approved['storeStatus'], $unchanged['body']['storeStatus']);
        $this->assertSame($approved['step'], $unchanged['body']['step']);
        $this->assertSame('', $unchanged['body']['errorCode']);
    }

    public function testCreateAppleWebhookIgnored(): void
    {
        $prepared = $this->prepareAppleRelease(['configuration' => self::EXEMPT]);
        $appId = $prepared['appId'];
        $this->seedAppleUpload($prepared['storeAppId'], self::BUILD, 'FAILED');

        $created = $this->createSubmission($appId, [
            'releaseId' => $prepared['releaseId'],
            'targetId' => $prepared['targetId'],
            'artifactId' => $prepared['artifactId'],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->awaitSubmission($appId, $created['body']['$id'], 'failed');

        $webhooks = $this->appleStore($prepared['key'])->listWebhooks($prepared['storeAppId']);
        $this->assertCount(1, $webhooks);
        $webhookId = $webhooks[0]->id;
        $event = ['type' => self::BETA_TYPE, 'id' => \bin2hex(\random_bytes(16)), 'version' => 1];

        /**
         * Test for SUCCESS
         */
        $first = $this->deliverAppleEvent($webhookId, $event);
        $this->assertSame(204, $first['httpStatusCode']);

        $duplicate = $this->deliverAppleEvent($webhookId, $event);
        $this->assertSame(204, $duplicate['httpStatusCode'], 'A redelivered event id is accepted.');

        $anonymous = $this->deliverAppleEvent($webhookId, ['type' => self::BETA_TYPE, 'version' => 1]);
        $this->assertSame(204, $anonymous['httpStatusCode'], 'A signed event without data.id is accepted.');

        $other = $this->createAppleConnection();
        $this->assertSame(201, $other['headers']['status-code']);
        $moved = $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId . '/targets/' . $prepared['targetId'], $this->headers(), [
            'connectionId' => $other['body']['$id'],
        ]);
        $this->assertSame(200, $moved['headers']['status-code']);

        $stale = $this->deliverAppleEvent($webhookId, [...$event, 'id' => \bin2hex(\random_bytes(16))]);
        $this->assertSame(204, $stale['httpStatusCode'], 'An event for a target moved to another connection is accepted and ignored.');
        $this->assertSame(self::DELIVERED, $stale['deliveryState']);
    }

    public function testCreateAppleWebhookRefusals(): void
    {
        $connection = $this->createAppleConnection();
        $this->assertSame(201, $connection['headers']['status-code']);
        $connectionId = $connection['body']['$id'];
        $targetId = ID::unique();
        $projectId = $this->getProject()['$id'];
        $body = ['data' => ['type' => 'webhookPingCreated', 'id' => \bin2hex(\random_bytes(16)), 'version' => 1]];

        /**
         * Test for FAILURE
         */
        $unsigned = $this->deliver($connectionId, $targetId, $projectId, $body);
        $this->assertSame(401, $unsigned['headers']['status-code']);
        $this->assertSame('user_unauthorized', $unsigned['body']['type']);

        $forged = $this->deliver($connectionId, $targetId, $projectId, $body, Webhook::sign(\json_encode($body, JSON_THROW_ON_ERROR), 'not-the-secret'));
        $this->assertSame(401, $forged['headers']['status-code']);
        $this->assertSame('user_unauthorized', $forged['body']['type']);

        $unknown = $this->deliver(ID::unique(), $targetId, $projectId, $body, Webhook::sign(\json_encode($body, JSON_THROW_ON_ERROR), 'not-the-secret'));
        $this->assertSame(401, $unknown['headers']['status-code']);
        $this->assertSame('user_unauthorized', $unknown['body']['type']);

        $projectless = $this->deliver($connectionId, $targetId, '', $body, Webhook::sign(\json_encode($body, JSON_THROW_ON_ERROR), 'not-the-secret'));
        $this->assertSame(401, $projectless['headers']['status-code']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function appleDeliveries(string $webhookId): array
    {
        $deliveries = $this->client->call(Client::METHOD_GET, '/mock/distribution/apple/seeds/deliveries', $this->mockHeaders(), [
            'webhookId' => $webhookId,
        ]);
        $this->assertSame(200, $deliveries['headers']['status-code'], 'Reading App Store Connect webhook deliveries requires _APP_ENV=development.');

        return $deliveries['body']['deliveries'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function deliverAppleEvent(string $webhookId, array $data): array
    {
        $delivered = $this->client->call(Client::METHOD_POST, '/mock/distribution/apple/seeds/deliveries', $this->mockHeaders(), [
            'webhookId' => $webhookId,
            'eventType' => Webhook::EVENT_BETA_STATE,
            'data' => $data,
        ]);
        $this->assertSame(201, $delivered['headers']['status-code'], 'Seeding an App Store Connect webhook delivery requires _APP_ENV=development.');

        return $delivered['body'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function deliver(string $connectionId, string $targetId, string $projectId, array $body, string $signature = ''): array
    {
        $headers = ['content-type' => 'application/json'];

        if ($signature !== '') {
            $headers[Webhook::HEADER] = $signature;
        }

        $query = $projectId === '' ? '' : '?' . \http_build_query(['project' => $projectId]);

        return $this->client->call(Client::METHOD_POST, '/distribution/webhooks/apple/' . $connectionId . '/' . $targetId . $query, $headers, $body);
    }
}
