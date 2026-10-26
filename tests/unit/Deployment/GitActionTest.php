<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\GitAction;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\VCS\Adapter\Git;

final class GitActionTest extends TestCase
{
    private const array PLATFORM = ['consoleUrl' => 'https://cloud.appwrite.io/console'];

    /** @var list<array<string, string>> The deployments reported to the provider, in order */
    private array $deployments = [];

    /** @var list<array<string, string>> The checks reported to the provider, in order */
    private array $checks = [];

    public function testASiteBuildIsReportedWithItsPreviewAndLogs(): void
    {
        GitAction::run('ready', $this->vcs(), 'abc123', 'owner', 'repository', $this->project(), $this->site(), new Document(['$id' => 'build', '$sequence' => '7']), $this->dbForPlatform(), self::PLATFORM);

        $logs = 'https://cloud.appwrite.io/console/projects/production/sites/landing/deployments/build';

        // The deployment shows where the site runs and where its build logs are
        $this->assertCount(1, $this->deployments);
        $deployment = $this->deployments[0];
        $this->assertSame('production/landing', $deployment['key']);
        $this->assertSame('Landing', $deployment['name']);
        $this->assertSame('success', $deployment['state']);
        $this->assertSame('preview', $deployment['environment']);
        $this->assertStringEndsWith('://landing-build.appwrite.network', $deployment['url']);
        $this->assertSame($logs, $deployment['logsUrl']);
        $this->assertSame('site', $deployment['type']);
        $this->assertSame('production', $deployment['groupKey']);
        $this->assertSame('Production', $deployment['groupName']);

        // The check answers whether the build passed, so it links to the logs
        $this->assertCount(1, $this->checks);
        $this->assertSame(['state' => 'success', 'name' => 'Landing', 'detailsUrl' => $logs, 'suite' => 'Production'], $this->checks[0]);
    }

    public function testAProductionFunctionBuildHasNoPreview(): void
    {
        $function = new Document(['$id' => 'billing', '$collection' => 'functions', 'name' => 'Billing']);

        GitAction::deployment('processing', $this->vcs(), 'abc123', 'owner', 'repository', $this->project(), $function, new Document(['$id' => 'build', '$sequence' => '7', 'activate' => true]), $this->dbForPlatform(), self::PLATFORM);

        $deployment = $this->deployments[0];
        $this->assertSame('pending', $deployment['state']);
        $this->assertSame('production', $deployment['environment'], 'A production branch build is the one that goes live');
        $this->assertSame('', $deployment['url']);
        $this->assertSame('function', $deployment['type']);
        $this->assertSame('https://cloud.appwrite.io/console/projects/production/functions/billing/deployments/build', $deployment['logsUrl']);
    }

    public function testADeletedDeploymentOnlyRetiresItsResource(): void
    {
        GitAction::run('deleted', $this->vcs(), 'abc123', 'owner', 'repository', $this->project(), $this->site(), new Document(['$id' => 'build', '$sequence' => '7']), $this->dbForPlatform(), self::PLATFORM);

        $this->assertSame([], $this->checks, 'The build result stands after its deployment is deleted');
        $this->assertSame('inactive', $this->deployments[0]['state']);
        $this->assertSame('', $this->deployments[0]['url']);
    }

    private function vcs(): Git
    {
        $vcs = $this->createStub(Git::class);
        $vcs->method('updateDeployment')->willReturnCallback(function (string $owner, string $repositoryName, string $SHA, string $key, string $name, string $state, string $environment = 'preview', string $url = '', string $logsUrl = '', string $type = '', string $groupKey = '', string $groupName = ''): void {
            $this->deployments[] = [
                'key' => $key,
                'name' => $name,
                'state' => $state,
                'environment' => $environment,
                'url' => $url,
                'logsUrl' => $logsUrl,
                'type' => $type,
                'groupKey' => $groupKey,
                'groupName' => $groupName,
            ];
        });
        $vcs->method('updateCheck')->willReturnCallback(function (string $owner, string $repositoryName, string $SHA, string $state, string $name, string $title, string $summary = '', string $detailsUrl = '', string $suite = ''): void {
            $this->checks[] = ['state' => $state, 'name' => $name, 'detailsUrl' => $detailsUrl, 'suite' => $suite];
        });

        return $vcs;
    }

    private function project(): Document
    {
        return new Document(['$id' => 'production', '$sequence' => '1', 'name' => 'Production']);
    }

    private function site(): Document
    {
        return new Document(['$id' => 'landing', '$collection' => 'sites', 'name' => 'Landing']);
    }

    /**
     * A platform database whose every deployment has a preview rule.
     */
    private function dbForPlatform(): Database
    {
        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform->method('findOne')->willReturn(new Document(['domain' => 'landing-build.appwrite.network']));

        return $dbForPlatform;
    }
}
