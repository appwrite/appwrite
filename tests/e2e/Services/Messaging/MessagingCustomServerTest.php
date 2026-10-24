<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Messaging;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;

final class MessagingCustomServerTest extends Scope
{
    use MessagingBase;
    use ProjectCustom;
    use SideServer;

    public function testEmailAttachmentsRequireFilesRead(): void
    {
        $projectId = $this->getProject()['$id'];
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $withoutFiles = \array_merge($headers, [
            'x-appwrite-key' => $this->getNewKey(['messages.read', 'messages.write']),
        ]);

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => ID::unique(),
            'name' => 'Attachments scope',
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', \array_merge($headers, [
            'content-type' => 'multipart/form-data',
        ]), [
            'fileId' => ID::unique(),
            'file' => new CURLFile(\realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);
        $this->assertSame(201, $file['headers']['status-code']);

        $attachment = $bucket['body']['$id'] . ':' . $file['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $draft = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', $headers, [
            'messageId' => ID::unique(),
            'subject' => 'Attachment scope',
            'content' => 'See attached',
            'attachments' => [$attachment],
            'draft' => true,
        ]);
        $this->assertSame(201, $draft['headers']['status-code']);

        $plain = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', $withoutFiles, [
            'messageId' => ID::unique(),
            'subject' => 'No attachment',
            'content' => 'No attachment',
            'draft' => true,
        ]);
        $this->assertSame(201, $plain['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', $withoutFiles, [
            'messageId' => ID::unique(),
            'subject' => 'Attachment scope',
            'content' => 'See attached',
            'attachments' => [$attachment],
            'draft' => true,
        ]);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $plain['body']['$id'], $withoutFiles, [
            'attachments' => [$attachment],
        ]);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $draft['body']['$id'], $withoutFiles, [
            'subject' => 'Changed',
            'scheduledAt' => DateTime::addSeconds(new \DateTime(), 86400),
        ]);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $response['body']['type']);

        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $draft['body']['$id'], $headers);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('draft', $response['body']['status']);
        $this->assertSame('Attachment scope', $response['body']['data']['subject']);
        $this->assertNull($response['body']['scheduledAt']);

        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $draft['body']['$id'], $withoutFiles, [
            'attachments' => [],
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
    }
}
