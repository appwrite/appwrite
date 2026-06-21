<?php

declare(strict_types=1);

namespace Tests\Unit\Functions;

use Appwrite\Platform\Modules\Functions\Workers\Builds;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Utopia\Config\Config;
use Utopia\Database\Document;

final class BuildsTest extends TestCase
{
    private Builds $builds;

    public function setUp(): void
    {
        $this->builds = new Builds();
    }

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
            $this->callBuilds('getCommand', $resource, $deployment)
        );
    }

    public function testSiteBuildCommandAppendsDetectionFileListing(): void
    {
        $command = $this->callBuilds('prepareSiteBuildCommand', 'npm run build', './dist folder');

        $this->assertStringStartsWith('npm run build && echo "{APPWRITE_DETECTION_SEPARATOR_START}"', $command);
        $this->assertStringContainsString('cd /usr/local/build && cd \'./dist folder\'', (string) $command);
        $this->assertStringContainsString('find . -name \'node_modules\' -prune -o -type f -print', (string) $command);
        $this->assertStringEndsWith('echo "{APPWRITE_DETECTION_SEPARATOR_END}"', $command);
    }

    public function testSiteBuildCommandCanBeOnlyDetectionFileListing(): void
    {
        $command = $this->callBuilds('prepareSiteBuildCommand', '', '');

        $this->assertStringStartsWith('echo "{APPWRITE_DETECTION_SEPARATOR_START}"', $command);
        $this->assertStringContainsString('cd /usr/local/build && find .', (string) $command);
    }

    public function testSplitSiteDetectionLogsRemovesDetectionBlock(): void
    {
        $result = $this->callBuilds(
            'splitSiteDetectionLogs',
            "before\n{APPWRITE_DETECTION_SEPARATOR_START}\n./index.html\n./server/entry.mjs\n{APPWRITE_DETECTION_SEPARATOR_END}\nafter\n"
        );

        $this->assertSame("before\n\nafter\n", $result['logs']);
        $this->assertSame("\n./index.html\n./server/entry.mjs\n", $result['detectionLogs']);
    }

    public function testDetectSiteRenderingFindsStaticFallbackFile(): void
    {
        $detection = $this->callBuilds('detectSiteRendering', 'other', "./main.html\n");

        $this->assertSame('static', $detection->getName());
        $this->assertSame('main.html', $detection->getFallbackFile());
    }

    public function testDetectSiteRenderingFindsAstroSSR(): void
    {
        $detection = $this->callBuilds('detectSiteRendering', 'astro', "./client/index.html\n./server/entry.mjs\n");

        $this->assertSame('ssr', $detection->getName());
        $this->assertNull($detection->getFallbackFile());
    }

    private function callBuilds(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($this->builds, $method))->invoke($this->builds, ...$arguments);
    }
}
