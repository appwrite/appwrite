<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Auth\Scopes;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V26;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class V26Test extends TestCase
{
    public function testKeyAndWebhookSecretsNeedTheirWriteScopes(): void
    {
        $project = $this->parse(new Scopes());

        $this->assertSame('', $project['keys'][0]['secret']);
        $this->assertSame('', $project['webhooks'][0]['secret']);
        $this->assertSame('', $project['webhooks'][0]['authPassword']);
        // The rest of the shape is unchanged
        $this->assertSame('key1', $project['keys'][0]['$id']);
        $this->assertSame(['users.read'], $project['keys'][0]['scopes']);
        $this->assertSame('https://example.com/hook', $project['webhooks'][0]['url']);
    }

    public function testManagersStillReadTheSecrets(): void
    {
        $project = $this->parse((new Scopes())->grant(['keys.write', 'webhooks.write']));

        $this->assertSame('standard_secret', $project['keys'][0]['secret']);
        $this->assertSame('signature-key', $project['webhooks'][0]['secret']);
        $this->assertSame('basic-pass', $project['webhooks'][0]['authPassword']);
    }

    public function testKeysWriteDoesNotRevealWebhookSecrets(): void
    {
        $project = $this->parse((new Scopes())->grant(['keys.write']));

        $this->assertSame('standard_secret', $project['keys'][0]['secret']);
        $this->assertSame('', $project['webhooks'][0]['secret']);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(Scopes $scopes): array
    {
        $filter = new V26($scopes);
        $filter->setRawContent(new Document([
            '$id' => 'project1',
            'keys' => [new Document([
                '$id' => 'key1',
                'name' => 'Key',
                'scopes' => ['users.read'],
                'secret' => 'standard_secret',
            ])],
            'webhooks' => [new Document([
                '$id' => 'webhook1',
                'url' => 'https://example.com/hook',
                'httpUser' => 'basic-user',
                'httpPass' => 'basic-pass',
                'signatureKey' => 'signature-key',
            ])],
        ]));

        return $filter->parse(['$id' => 'project1'], Response::MODEL_PROJECT);
    }
}
