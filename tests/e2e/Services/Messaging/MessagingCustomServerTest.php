<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Messaging;

use CURLFile;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\DateTime;
use Utopia\Database\Id;

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
            'bucketId' => Id::unique(),
            'name' => 'Attachments scope',
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', \array_merge($headers, [
            'content-type' => 'multipart/form-data',
        ]), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(\realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);
        $this->assertSame(201, $file['headers']['status-code']);

        $attachment = $bucket['body']['$id'] . ':' . $file['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $draft = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', $headers, [
            'messageId' => Id::unique(),
            'subject' => 'Attachment scope',
            'content' => 'See attached',
            'attachments' => [$attachment],
            'draft' => true,
        ]);
        $this->assertSame(201, $draft['headers']['status-code']);

        $plain = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', $withoutFiles, [
            'messageId' => Id::unique(),
            'subject' => 'No attachment',
            'content' => 'No attachment',
            'draft' => true,
        ]);
        $this->assertSame(201, $plain['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', $withoutFiles, [
            'messageId' => Id::unique(),
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

    public function testMessageUpdateRejectsOtherProviderTypes(): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
        $withoutFiles = \array_merge($headers, [
            'x-appwrite-key' => $this->getNewKey(['messages.read', 'messages.write']),
        ]);

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => Id::unique(),
            'name' => 'Provider type attachments',
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);

        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', \array_merge($headers, [
            'content-type' => 'multipart/form-data',
        ]), [
            'fileId' => Id::unique(),
            'file' => new CURLFile(\realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);
        $this->assertSame(201, $file['headers']['status-code']);

        // The message needs a recipient so that the SMS and push updaters get past their own
        // missing-recipient check and reach the message itself.
        $topic = $this->client->call(Client::METHOD_POST, '/messaging/topics', $headers, [
            'topicId' => Id::unique(),
            'name' => 'Provider type',
        ]);
        $this->assertSame(201, $topic['headers']['status-code']);

        $email = $this->client->call(Client::METHOD_POST, '/messaging/messages/email', $headers, [
            'messageId' => Id::unique(),
            'topics' => [$topic['body']['$id']],
            'subject' => 'Provider type',
            'content' => 'See attached',
            'attachments' => [$bucket['body']['$id'] . ':' . $file['body']['$id']],
            'draft' => true,
        ]);
        $this->assertSame(201, $email['headers']['status-code']);

        $sms = $this->client->call(Client::METHOD_POST, '/messaging/messages/sms', $headers, [
            'messageId' => Id::unique(),
            'content' => 'Provider type',
            'draft' => true,
        ]);
        $this->assertSame(201, $sms['headers']['status-code']);

        $push = $this->client->call(Client::METHOD_POST, '/messaging/messages/push', $headers, [
            'messageId' => Id::unique(),
            'title' => 'Provider type',
            'body' => 'Provider type',
            'draft' => true,
        ]);
        $this->assertSame(201, $push['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        // The worker delivers a message as whatever the stored document says it is, so neither the SMS
        // nor the push updater may touch an email message: its attachments would be read and mailed
        // out on the authority of a key that cannot read files, to recipients this request picks.
        foreach (['sms', 'push'] as $type) {
            $response = $this->client->call(Client::METHOD_PATCH, "/messaging/messages/{$type}/" . $email['body']['$id'], $withoutFiles, [
                'draft' => false,
            ]);
            $this->assertSame(404, $response['headers']['status-code']);
            $this->assertSame('message_not_found', $response['body']['type']);
        }

        // Its own route refuses the same request for the attachments it carries.
        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $email['body']['$id'], $withoutFiles, [
            'draft' => false,
        ]);
        $this->assertSame(401, $response['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $response['body']['type']);

        // Nothing moved: the message is still a draft with its attachment.
        $response = $this->client->call(Client::METHOD_GET, '/messaging/messages/' . $email['body']['$id'], $headers);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('draft', $response['body']['status']);
        $this->assertCount(1, $response['body']['data']['attachments']);

        // The mismatch is refused in every direction.
        $mismatches = [
            'email' => $sms['body']['$id'],
            'sms' => $push['body']['$id'],
            'push' => $sms['body']['$id'],
        ];

        foreach ($mismatches as $type => $messageId) {
            $response = $this->client->call(Client::METHOD_PATCH, "/messaging/messages/{$type}/" . $messageId, $headers, [
                'draft' => true,
            ]);
            $this->assertSame(404, $response['headers']['status-code']);
            $this->assertSame('message_not_found', $response['body']['type']);
        }

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/sms/' . $sms['body']['$id'], $withoutFiles, [
            'content' => 'Provider type updated',
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('Provider type updated', $response['body']['data']['content']);

        $response = $this->client->call(Client::METHOD_PATCH, '/messaging/messages/email/' . $email['body']['$id'], $headers, [
            'subject' => 'Provider type updated',
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('Provider type updated', $response['body']['data']['subject']);
    }
}
