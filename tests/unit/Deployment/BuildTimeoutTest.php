<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use OpenRuntimes\Orchestrator\Jobs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class BuildTimeoutTest extends TestCase
{
    public static function budgets(): array
    {
        return ['default' => [null, 900], 'operator override' => ['1800', 1800]];
    }

    #[DataProvider('budgets')]
    public function testSelfHostedSubmissionUsesOperatorBudget(?string $configured, int $expected): void
    {
        $previousKey = getenv('_APP_OPENSSL_KEY_V1');
        $previousTimeout = getenv('_APP_COMPUTE_BUILD_TIMEOUT');
        putenv('_APP_OPENSSL_KEY_V1=unit-test-key');
        putenv($configured === null ? '_APP_COMPUTE_BUILD_TIMEOUT' : '_APP_COMPUTE_BUILD_TIMEOUT=' . $configured);
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
            $service = new Deployments(new Jobs($client), $database, new Document(['$id' => 'project', 'region' => 'default']), ['apiHostname' => 'localhost']);

            $result = $service->createFromUpload(new Document([
                '$id' => 'function', '$collection' => 'functions',
                'runtime' => array_key_first(Config::getParam('runtimes-v2')),
            ]), clone $deployment);

            $this->assertSame('waiting', $result->getAttribute('status'));
            $this->assertCount(1, $requests);
            $this->assertSame($expected, $requests[0]['timeoutSeconds']);
        } finally {
            putenv($previousKey === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $previousKey);
            putenv($previousTimeout === false ? '_APP_COMPUTE_BUILD_TIMEOUT' : '_APP_COMPUTE_BUILD_TIMEOUT=' . $previousTimeout);
        }
    }
}
