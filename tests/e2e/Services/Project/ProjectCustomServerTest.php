<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\Database\Id;

final class ProjectCustomServerTest extends Scope
{
    use ProjectBase;
    use ProjectCustom;
    use SideServer;

    public function testGetProjectLegacyFormatHidesSecrets(): void
    {
        $projectHeaders = \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $webhook = $this->client->call(Client::METHOD_POST, '/webhooks', $projectHeaders, [
            'webhookId' => Id::unique(),
            'name' => 'Legacy Format Webhook',
            'events' => ['teams.*'],
            'url' => 'http://request-catcher-webhook:5000/',
            'authUsername' => 'user',
            'authPassword' => 'legacy-format-password',
            'secret' => 'legacy-format-signature',
        ]);

        $this->assertSame(201, $webhook['headers']['status-code']);
        $webhookId = $webhook['body']['$id'];

        $reader = $this->client->call(Client::METHOD_POST, '/project/keys/ephemeral', $projectHeaders, [
            'scopes' => ['project.read'],
            'duration' => 900,
        ]);

        $this->assertSame(201, $reader['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $project = $this->client->call(Client::METHOD_GET, '/project', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $reader['body']['secret'],
            'x-appwrite-response-format' => '1.9.4',
        ]);

        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertNotEmpty($project['body']['keys']);
        $this->assertSame([''], \array_unique(\array_column($project['body']['keys'], 'secret')));

        $webhooks = \array_column($project['body']['webhooks'], null, '$id');
        $this->assertArrayHasKey($webhookId, $webhooks);
        $this->assertSame('user', $webhooks[$webhookId]['authUsername']);
        $this->assertSame('', $webhooks[$webhookId]['authPassword']);
        $this->assertSame('', $webhooks[$webhookId]['secret']);

        // A caller that can manage keys still receives their secrets
        $project = $this->client->call(Client::METHOD_GET, '/project', \array_merge($projectHeaders, [
            'x-appwrite-response-format' => '1.9.4',
        ]));

        $this->assertSame(200, $project['headers']['status-code']);
        $this->assertContains($this->getProject()['apiKey'], \array_column($project['body']['keys'], 'secret'));

        $webhooks = \array_column($project['body']['webhooks'], null, '$id');
        $this->assertSame('', $webhooks[$webhookId]['authPassword']);
        $this->assertSame('', $webhooks[$webhookId]['secret']);

        $delete = $this->client->call(Client::METHOD_DELETE, '/webhooks/' . $webhookId, $projectHeaders);

        $this->assertSame(204, $delete['headers']['status-code']);
    }
}
