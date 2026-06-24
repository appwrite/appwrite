<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Platform\Modules\VCS\Http\GitHub\Events\Create;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class GitHubInstallationEventTest extends TestCase
{
    private Create $handler;

    public function setUp(): void
    {
        $this->handler = new Create();
    }

    public function testHandleInstallationEventIgnoresNonDeletedActions(): void
    {
        $dbForPlatform = $this->createMock(Database::class);
        $authorization = $this->createStub(Authorization::class);

        $dbForPlatform->expects($this->never())->method('find');
        $dbForPlatform->expects($this->never())->method('deleteDocument');

        $result = $this->callHandler('handleInstallationEvent', [
            'action' => 'created',
            'installationId' => '123',
        ], $dbForPlatform, $authorization, fn () => null);

        $this->assertNull($result);
    }

    public function testHandleInstallationEventDisconnectsFunctionsAndSites(): void
    {
        $installation = new Document([
            '$id' => 'install1',
            '$sequence' => 'install1seq',
            'projectId' => 'project1',
            'providerInstallationId' => 'gh-install-123',
        ]);

        $function = new Document([
            '$id' => 'func1',
            '$collection' => 'functions',
            'installationId' => 'install1',
            'installationInternalId' => 'install1seq',
            'providerRepositoryId' => 'repo123',
            'providerBranch' => 'main',
            'providerRootDirectory' => 'functions/my-func',
            'providerSilentMode' => true,
            'repositoryId' => 'repo1',
            'repositoryInternalId' => 'repo1seq',
        ]);

        $project = new Document(['$id' => 'project1']);

        $dbForProject = $this->createMock(Database::class);
        $dbForPlatform = $this->createMock(Database::class);
        $authorization = $this->createMock(Authorization::class);

        $dbForPlatform->expects($this->exactly(2))
            ->method('find')
            ->willReturnCallback(fn ($collection) => match ($collection) {
                'installations' => [$installation],
                'repositories' => [],
                default => [],
            });

        $dbForPlatform->expects($this->once())
            ->method('getDocument')
            ->with('projects', 'project1')
            ->willReturn($project);

        $dbForProject->expects($this->exactly(2))
            ->method('find')
            ->willReturnCallback(fn ($collection) => $collection === 'functions' ? [$function] : []);

        $dbForProject->expects($this->once())
            ->method('updateDocument')
            ->with('functions', 'func1', $this->callback(function (Document $doc) {
                return $doc->getAttribute('installationId') === ''
                    && $doc->getAttribute('installationInternalId') === ''
                    && $doc->getAttribute('providerRepositoryId') === ''
                    && $doc->getAttribute('providerBranch') === ''
                    && $doc->getAttribute('providerRootDirectory') === ''
                    && $doc->getAttribute('providerSilentMode') === false
                    && $doc->getAttribute('repositoryId') === ''
                    && $doc->getAttribute('repositoryInternalId') === '';
            }));

        $authorization->expects($this->exactly(2))
            ->method('skip')
            ->willReturnCallback(fn ($fn) => $fn());

        $dbForPlatform->expects($this->once())
            ->method('deleteDocument')
            ->with('installations', 'install1');

        $getProjectDB = fn () => $dbForProject;

        $this->callHandler('handleInstallationEvent', [
            'action' => 'deleted',
            'installationId' => 'gh-install-123',
        ], $dbForPlatform, $authorization, $getProjectDB);
    }

    public function testHandleInstallationEventSkipsDisconnectWhenProjectNotFound(): void
    {
        $installation = new Document([
            '$id' => 'install1',
            '$sequence' => 'install1seq',
            'projectId' => 'project1',
            'providerInstallationId' => 'gh-install-123',
        ]);

        $dbForPlatform = $this->createMock(Database::class);
        $authorization = $this->createMock(Authorization::class);

        $dbForPlatform->expects($this->exactly(2))
            ->method('find')
            ->willReturnCallback(fn ($collection) => match ($collection) {
                'installations' => [$installation],
                'repositories' => [],
                default => [],
            });

        $dbForPlatform->expects($this->once())
            ->method('getDocument')
            ->with('projects', 'project1')
            ->willReturn(new Document());

        $authorization->expects($this->exactly(2))
            ->method('skip')
            ->willReturnCallback(fn ($fn) => $fn());

        $dbForPlatform->expects($this->once())
            ->method('deleteDocument')
            ->with('installations', 'install1');

        $this->callHandler('handleInstallationEvent', [
            'action' => 'deleted',
            'installationId' => 'gh-install-123',
        ], $dbForPlatform, $authorization, fn () => null);
    }

    private function callHandler(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($this->handler, $method))->invoke($this->handler, ...$arguments);
    }
}
