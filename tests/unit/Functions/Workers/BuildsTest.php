<?php

declare(strict_types=1);

namespace Tests\Unit\Functions\Workers;

use Appwrite\Deployment\Deployments;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Functions\Workers\Builds;
use Appwrite\Usage\Context;
use Appwrite\Vcs\Factory as VcsFactory;
use OpenRuntimes\Orchestrator\Jobs;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Utopia\Config\Config;
use Utopia\Console\Command;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Queue\Message;
use Utopia\VCS\Adapter\Git;

final class BuildsTest extends TestCase
{
    public function testARefusedTemplateBuildKeepsItsReasonAndIsNotRetried(): void
    {
        // Signs the build's API key, so a deployment that slips past the
        // refusal reaches the job submission instead of failing on its payload.
        $previousKey = getenv('_APP_OPENSSL_KEY_V1');
        putenv('_APP_OPENSSL_KEY_V1=unit-test-key');
        try {
            $this->assertRefusedBuild();
        } finally {
            putenv($previousKey === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $previousKey);
        }
    }

    private function assertRefusedBuild(): void
    {
        $function = new Document([
            '$id' => 'function',
            '$sequence' => '10',
            '$collection' => 'functions',
            'name' => 'Starter',
            'runtime' => array_key_first(Config::getParam('runtimes-v2')),
        ]);
        $deployment = new Document([
            '$id' => 'deployment',
            '$sequence' => '1',
            'resourceType' => 'functions',
            'type' => 'vcs',
            'entrypoint' => 'index.js',
            'installationId' => 'installation',
            'providerRepositoryId' => '42',
            'providerBranch' => 'main',
            'providerCommitHash' => 'abc123',
            'status' => 'waiting',
            'activate' => true,
        ]);
        $documents = ['function' => $function, 'deployment' => $deployment];

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(static fn (string $collection, string $id) => clone $documents[$id]);
        $dbForProject->method('findOne')->willReturnCallback(static fn () => clone $deployment);
        $dbForProject->method('find')->willReturn([]);
        $dbForProject->method('updateDocument')->willReturnCallback(static function (string $collection, string $id, Document $update) use ($documents): Document {
            $documents[$id]->setAttributes($update->getArrayCopy());
            return clone $documents[$id];
        });
        $dbForProject->method('updateDocuments')->willReturnCallback(static function (string $collection, Document $update) use ($deployment): int {
            if ($deployment->getAttribute('status') === 'canceled') {
                return 0;
            }
            $deployment->setAttributes($update->getArrayCopy());
            return 1;
        });

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('getDocument')->willReturn(new Document(['$id' => 'installation', 'providerInstallationId' => '7']));
        $dbForPlatform->method('findOne')->willReturn(new Document());

        $commitStates = [];
        $git = $this->createStub(Git::class);
        $git->method('getOwnerName')->willReturn('owner');
        $git->method('getRepositoryName')->willReturn('repository');
        $git->method('generateCloneCommand')->willReturn(new Command('true'));
        $git->method('supportsRepositoryArchives')->willReturn(true);
        $git->method('getRepositoryPresignedUrl')->willReturn('https://example.com/archive.tar.gz');
        $git->method('updateCommitStatus')->willReturnCallback(static function (string $repository, string $sha, string $owner, string $state) use (&$commitStates): void {
            $commitStates[] = $state;
        });

        $vcsFactory = $this->createStub(VcsFactory::class);
        $vcsFactory->method('fromProvider')->willReturn($git);
        $vcsFactory->method('fromInstallation')->willReturn($git);

        $requests = 0;
        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(static function (RequestInterface $request) use (&$requests): Response {
            $requests++;
            return new Response(202, body: new Stream('{"id":"build","status":"accepted"}'));
        });

        $project = new Document(['$id' => 'project', '$sequence' => '5', 'name' => 'Project', 'region' => 'default']);
        $deployments = new readonly class (new Jobs($client), $dbForProject, $project, ['apiHostname' => 'localhost']) extends Deployments {
            protected function admit(Document $resource, Document $deployment): void
            {
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Deployment limit reached');
            }
        };

        (new Builds())->action(
            new Message([
                'pid' => 'message',
                'queue' => 'builds',
                'timestamp' => \time(),
                'payload' => [
                    'type' => BUILD_TYPE_DEPLOYMENT,
                    'resource' => $function->getArrayCopy(),
                    'deployment' => $deployment->getArrayCopy(),
                    'template' => [],
                    'platform' => ['apiHostname' => 'localhost'],
                ],
            ]),
            $project,
            $dbForPlatform,
            $this->createStub(Realtime::class),
            new Context(),
            $this->createStub(UsagePublisher::class),
            $vcsFactory,
            $dbForProject,
            static fn () => false,
            $deployments,
        );

        $this->assertSame('failed', $deployment->getAttribute('status'));
        $this->assertStringContainsString('Deployment limit reached', $deployment->getAttribute('buildLogs'));
        $this->assertStringNotContainsString('internal error', $deployment->getAttribute('buildLogs'));
        $this->assertSame(['failure'], $commitStates, 'The commit must be reported as failed');
        $this->assertSame(0, $requests, 'No build job may be submitted');
    }
}
