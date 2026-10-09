<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use Appwrite\Extend\Exception;
use OpenRuntimes\Orchestrator\Jobs;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class AdmitTest extends TestCase
{
    public function testRefusedDeploymentFailsWithoutTakingOverOrBuilding(): void
    {
        // Signs the build's API key, so a deployment that slips past the
        // refusal reaches the job submission instead of failing on its payload.
        $previousKey = getenv('_APP_OPENSSL_KEY_V1');
        putenv('_APP_OPENSSL_KEY_V1=unit-test-key');
        try {
            $this->assertRefusal();
        } finally {
            putenv($previousKey === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $previousKey);
        }
    }

    private function assertRefusal(): void
    {
        $deployment = new Document(['$id' => 'deployment', '$sequence' => '1', 'type' => 'manual', 'activate' => true]);
        $active = new Document(['$id' => 'active', '$sequence' => '2', 'activate' => true]);
        $documents = ['deployment' => $deployment, 'active' => $active];

        $database = $this->createStub(Database::class);
        $database->method('updateDocument')->willReturnCallback(static function (string $collection, string $id, Document $update) use ($documents): Document {
            $documents[$id]->setAttributes($update->getArrayCopy());
            return clone $documents[$id];
        });
        $database->method('updateDocuments')->willReturnCallback(static function (string $collection, Document $update) use ($deployment): int {
            $deployment->setAttributes($update->getArrayCopy());
            return 1;
        });
        $database->method('getDocument')->willReturnCallback(static fn (string $collection, string $id) => clone $documents[$id]);
        $database->method('find')->willReturnCallback(static fn () => [clone $active]);

        $requests = 0;
        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(static function (RequestInterface $request) use (&$requests): Response {
            $requests++;
            return new Response(202, body: new Stream('{"id":"build","status":"accepted"}'));
        });

        $deployments = new RefusingDeployments(new Jobs($client), $database, new Document(['$id' => 'project', 'region' => 'default']), ['apiHostname' => 'localhost']);

        try {
            $deployments->createFromUpload(new Document([
                '$id' => 'function', '$collection' => 'functions', 'runtime' => array_key_first(Config::getParam('runtimes-v2')),
            ]), clone $deployment, 137);
            $this->fail('A refused deployment must not be returned as created');
        } catch (Exception $error) {
            $this->assertSame(Exception::GENERAL_RATE_LIMIT_EXCEEDED, $error->getType());
        }

        $this->assertSame('failed', $deployment->getAttribute('status'));
        $this->assertStringContainsString('Deployment limit reached', $deployment->getAttribute('buildLogs'));
        $this->assertNotEmpty($deployment->getAttribute('buildEndedAt'));
        $this->assertTrue($active->getAttribute('activate'), 'The live deployment must stay active');
        $this->assertSame(0, $requests, 'No build job may be submitted');
    }
}

final readonly class RefusingDeployments extends Deployments
{
    protected function admit(Document $resource, Document $deployment): void
    {
        throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Deployment limit reached');
    }
}
