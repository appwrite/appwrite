<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Auth\Key;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V26;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Database\Document;

final class V26Test extends TestCase
{
    public function testOmitsKeySecretWithoutWriteScope(): void
    {
        $content = $this->parse(['project.read']);

        $this->assertSame('key', $content['keys'][0]['$id']);
        $this->assertSame('', $content['keys'][0]['secret']);
    }

    public function testKeepsKeySecretWithWriteScope(): void
    {
        $content = $this->parse(['project.read', 'keys.write']);

        $this->assertSame('standard_secret', $content['keys'][0]['secret']);
    }

    public function testKeepsKeySecretWithOrganizationWriteScope(): void
    {
        $content = $this->parse(['projects.read', 'organization.projects.keys.write']);

        $this->assertSame('standard_secret', $content['keys'][0]['secret']);
    }

    public function testOmitsKeySecretBeyondTheCallingKeysGrant(): void
    {
        $caller = new Key('project', '', '', API_KEY_STANDARD, User::ROLE_KEYS, ['project.read', 'keys.write'], 'Caller');

        $content = $this->parse(['project.read', 'keys.write'], $caller);

        $this->assertSame('', $content['keys'][0]['secret']);
    }

    public function testKeepsKeySecretWithinTheCallingKeysGrant(): void
    {
        $caller = new Key('project', '', '', API_KEY_STANDARD, User::ROLE_KEYS, ['project.read', 'keys.write', 'users.read'], 'Caller');

        $content = $this->parse(['project.read', 'keys.write'], $caller);

        $this->assertSame('standard_secret', $content['keys'][0]['secret']);
    }

    public function testOmitsKeySecretForJWTCallers(): void
    {
        $swoole = new SwooleRequest();
        $swoole->header = ['x-appwrite-jwt' => 'jwt'];
        $request = new Request($swoole);
        $request->setScopes(['project.read', 'keys.write']);
        $filter = new V26($request);
        $filter->setRawContent($this->project());

        $content = $filter->parse(['$id' => 'project'], Response::MODEL_PROJECT);

        $this->assertSame('', $content['keys'][0]['secret']);
    }

    public function testOmitsWebhookCredentials(): void
    {
        $content = $this->parse(['project.read', 'keys.write', 'webhooks.write']);

        $this->assertSame('webhook', $content['webhooks'][0]['$id']);
        $this->assertSame('user', $content['webhooks'][0]['authUsername']);
        $this->assertSame('', $content['webhooks'][0]['authPassword']);
        $this->assertSame('', $content['webhooks'][0]['secret']);
    }

    public function testOmitsSecretsInProjectList(): void
    {
        $request = new Request(new SwooleRequest());
        $request->setScopes(['projects.read']);
        $filter = new V26($request);
        $filter->setRawContent(new Document([
            'projects' => [$this->project()],
        ]));

        $content = $filter->parse([
            'total' => 1,
            'projects' => [['$id' => 'project']],
        ], Response::MODEL_PROJECT_LIST);

        $this->assertSame('', $content['projects'][0]['keys'][0]['secret']);
        $this->assertSame('', $content['projects'][0]['webhooks'][0]['authPassword']);
        $this->assertSame('', $content['projects'][0]['webhooks'][0]['secret']);
    }

    /**
     * @param array<string> $scopes
     * @return array<string, mixed>
     */
    private function parse(array $scopes, ?Key $apiKey = null): array
    {
        $request = new Request(new SwooleRequest());
        $request->setScopes($scopes);
        $filter = new V26($request, $apiKey);
        $filter->setRawContent($this->project());

        return $filter->parse(['$id' => 'project'], Response::MODEL_PROJECT);
    }

    private function project(): Document
    {
        return new Document([
            '$id' => 'project',
            'keys' => [
                new Document([
                    '$id' => 'key',
                    'name' => 'Key',
                    'scopes' => ['users.read'],
                    'secret' => 'standard_secret',
                ]),
            ],
            'webhooks' => [
                new Document([
                    '$id' => 'webhook',
                    'httpUser' => 'user',
                    'httpPass' => 'password',
                    'signatureKey' => 'signature',
                ]),
            ],
        ]);
    }
}
