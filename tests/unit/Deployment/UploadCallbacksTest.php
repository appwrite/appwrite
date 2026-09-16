<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use OpenRuntimes\Orchestrator\Jobs;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class UploadCallbacksTest extends TestCase
{
    public function testManualUploadSubscribesToArtifactCallbacks(): void
    {
        $previousKey = getenv('_APP_OPENSSL_KEY_V1');
        putenv('_APP_OPENSSL_KEY_V1=unit-test-key');
        try {
            $deployment = new Document(['$id' => 'deployment', '$sequence' => '1', 'type' => 'manual', 'buildCommands' => 'npm ci']);
            $database = $this->createStub(Database::class);
            $database->method('updateDocument')->willReturnCallback(static function (string $collection, string $id, Document $update) use ($deployment): Document {
                $deployment->setAttributes($update->getArrayCopy());
                return clone $deployment;
            });
            $database->method('updateDocuments')->willReturnCallback(static function (string $collection, Document $update) use ($deployment): int {
                $deployment->setAttributes($update->getArrayCopy());
                return 1;
            });
            $database->method('getDocument')->willReturnCallback(static fn () => clone $deployment);
            $requests = [];
            $client = $this->createStub(ClientInterface::class);
            $client->method('sendRequest')->willReturnCallback(static function (RequestInterface $request) use (&$requests): Response {
                $requests[] = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                return new Response(202, body: new Stream('{"id":"build","status":"accepted"}'));
            });
            $deployments = new Deployments(new Jobs($client), $database, new Document(['$id' => 'project', 'region' => 'default']), ['apiHostname' => 'localhost']);
            $result = $deployments->createFromUpload(new Document([
                '$id' => 'function', '$collection' => 'functions', 'runtime' => array_key_first(Config::getParam('runtimes-v2')),
            ]), clone $deployment);

            $this->assertSame('waiting', $result->getAttribute('status'));
            $this->assertCount(1, $requests);
            // The real SDK serializes the callback filter sent to the service;
            // without this event a failed pre-job extraction is never delivered.
            $this->assertContains('orchestrator.job.artifact', $requests[0]['callback']['events']);
            $this->assertSame('deployment', $requests[0]['meta']['deploymentId']);
        } finally {
            putenv($previousKey === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $previousKey);
        }
    }
}
