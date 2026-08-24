<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Deployments;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Document;

final class DeploymentsTest extends TestCase
{
    public function testSiteCommandIncludesFrameworkAndDeploymentCommands(): void
    {
        Config::setParam('frameworks', [
            'astro' => [
                'envCommand' => 'cp .env.example .env',
                'bundleCommand' => 'npm run bundle',
            ],
        ]);

        $resource = new Document([
            '$collection' => 'sites',
            'framework' => 'astro',
        ]);
        $deployment = new Document([
            'buildCommands' => 'npm run build',
        ]);

        $this->assertSame(
            'cp .env.example .env && npm run build && npm run bundle',
            Deployments::command($resource, $deployment)
        );
    }

    public function testFunctionCommandIsDeploymentBuildCommands(): void
    {
        $resource = new Document(['$collection' => 'functions']);
        $deployment = new Document(['buildCommands' => 'npm install']);

        $this->assertSame('npm install', Deployments::command($resource, $deployment));
    }

    public function testEmptyStartCommandUsesDefault(): void
    {
        $this->assertSame(
            'bash helpers/server.sh',
            Deployments::startCommand(new Document(['startCommand' => '']), 'bash helpers/server.sh')
        );
    }

    public function testPersistedDefaultStartCommandDoesNotCdIntoSource(): void
    {
        $default = 'bash helpers/server.sh';

        $this->assertSame(
            $default,
            Deployments::startCommand(new Document(['startCommand' => $default]), $default)
        );
    }

    public function testPersistedFrameworkSsrDefaultStartCommandDoesNotCdIntoSource(): void
    {
        $default = 'bash helpers/angular/server.sh';

        $this->assertSame(
            $default,
            Deployments::startCommand(new Document(['startCommand' => $default]), $default)
        );
    }

    public function testCustomStartCommandCdsIntoSourceAndEscapes(): void
    {
        $this->assertSame(
            'cd /usr/local/server/src/function/ && npm start --prefix=\"\$HOME\"',
            Deployments::startCommand(
                new Document(['startCommand' => 'npm start --prefix="$HOME"']),
                'bash helpers/server.sh'
            )
        );
    }

    public function testCustomStartCommandEscapesBackticksAndQuotes(): void
    {
        $this->assertSame(
            'cd /usr/local/server/src/function/ && echo \"hi\" && echo \`id\`',
            Deployments::startCommand(
                new Document(['startCommand' => 'echo "hi" && echo `id`']),
                'bash helpers/server.sh'
            )
        );
    }

    public function testScopesMergeGrantsForResourceType(): void
    {
        Config::setParam('computeScopes', [
            'functions' => ['health.read'],
            'sites' => ['proxy.invalidations.write'],
        ]);

        $function = new Document([
            '$collection' => 'functions',
            'scopes' => ['users.read'],
        ]);

        $this->assertSame(['users.read', 'health.read'], Deployments::scopes($function));

        // Deduplicates when the resource already holds a granted scope
        $site = new Document([
            '$collection' => 'sites',
            'scopes' => ['users.read', 'proxy.invalidations.write'],
        ]);

        $this->assertSame(['users.read', 'proxy.invalidations.write'], Deployments::scopes($site));
    }

    public function testScopesEmptyGrantsKeepResourceScopes(): void
    {
        Config::setParam('computeScopes', ['functions' => [], 'sites' => []]);

        $site = new Document([
            '$collection' => 'sites',
            'scopes' => ['users.read'],
        ]);

        $this->assertSame(['users.read'], Deployments::scopes($site));
    }

    public function testShouldGoLiveRejectsWhenActivateIsFalse(): void
    {
        $candidate = new Document([
            '$id' => 'new',
            '$createdAt' => '2026-08-20T12:00:00.000+00:00',
            'activate' => false,
        ]);

        $this->assertFalse(Deployments::shouldGoLive($candidate, new Document()));
    }

    public function testShouldGoLiveActivatesWhenNothingIsLive(): void
    {
        $candidate = new Document([
            '$id' => 'new',
            '$createdAt' => '2026-08-20T12:00:00.000+00:00',
            'activate' => true,
        ]);

        $this->assertTrue(Deployments::shouldGoLive($candidate, new Document()));
    }

    public function testShouldGoLiveReplacesAnOlderLiveDeployment(): void
    {
        $candidate = new Document([
            '$id' => 'new',
            '$createdAt' => '2026-08-20T13:00:00.000+00:00',
            'activate' => true,
        ]);
        $current = new Document([
            '$id' => 'old',
            '$createdAt' => '2026-08-20T12:00:00.000+00:00',
        ]);

        $this->assertTrue(Deployments::shouldGoLive($candidate, $current));
    }

    public function testShouldGoLiveSkipsWhenTheLiveDeploymentIsNewer(): void
    {
        $candidate = new Document([
            '$id' => 'old',
            '$createdAt' => '2026-08-20T12:00:00.000+00:00',
            'activate' => true,
        ]);
        $current = new Document([
            '$id' => 'new',
            '$createdAt' => '2026-08-20T13:00:00.000+00:00',
        ]);

        $this->assertFalse(Deployments::shouldGoLive($candidate, $current));
    }

    public function testShouldGoLiveIsIdempotentForTheLiveDeployment(): void
    {
        $deployment = new Document([
            '$id' => 'live',
            '$createdAt' => '2026-08-20T12:00:00.000+00:00',
            'activate' => true,
        ]);

        $this->assertTrue(Deployments::shouldGoLive($deployment, $deployment));
    }

    public function testShouldGoLiveTreatsIntegerOneAsRequested(): void
    {
        $candidate = new Document([
            '$id' => 'new',
            '$createdAt' => '2026-08-20T12:00:00.000+00:00',
            'activate' => 1,
        ]);

        $this->assertTrue(Deployments::shouldGoLive($candidate, new Document()));
    }
}
