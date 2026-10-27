<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Vcs\Comment;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class CommentTest extends TestCase
{
    public function testTipIsPreservedAcrossMultipleGenerations(): void
    {
        $comment = new Comment(['consoleHostname' => 'localhost']);
        $comment->addBuild(
            new Document(['$id' => 'project1', 'name' => 'Test Project', 'region' => 'default']),
            new Document(['$id' => 'func1', 'name' => 'Test Function']),
            'function',
            'ready',
            'dep1',
            ['type' => 'logs'],
            ''
        );

        $first = $comment->generateComment();
        $firstTip = $this->extractTip($first);

        $this->assertNotNull($firstTip);
        $this->assertNotEmpty($firstTip);

        $second = $comment->generateComment();
        $secondTip = $this->extractTip($second);

        $this->assertSame($firstTip, $secondTip);
    }

    public function testTipIsRestoredFromParsedComment(): void
    {
        $comment = new Comment(['consoleHostname' => 'localhost']);
        $comment->addBuild(
            new Document(['$id' => 'project1', 'name' => 'Test Project', 'region' => 'default']),
            new Document(['$id' => 'func1', 'name' => 'Test Function']),
            'function',
            'ready',
            'dep1',
            ['type' => 'logs'],
            ''
        );

        $original = $comment->generateComment();
        $originalTip = $this->extractTip($original);

        $parsed = new Comment(['consoleHostname' => 'localhost']);
        $parsed->parseComment($original);
        $parsed->addBuild(
            new Document(['$id' => 'project1', 'name' => 'Test Project', 'region' => 'default']),
            new Document(['$id' => 'func2', 'name' => 'Another Function']),
            'function',
            'building',
            'dep2',
            ['type' => 'logs'],
            ''
        );

        $regenerated = $parsed->generateComment();
        $regeneratedTip = $this->extractTip($regenerated);

        $this->assertEquals($originalTip, $regeneratedTip);
    }

    public function testBuildWithoutDeploymentLinksNoLogs(): void
    {
        // A pull request can open on a commit nothing deployed yet
        $comment = new Comment(['consoleUrl' => 'https://cloud.appwrite.io/console', 'consoleHostname' => 'cloud.appwrite.io']);
        $project = new Document(['$id' => 'project1', 'name' => 'Test Project', 'region' => 'default']);
        $comment->addBuild($project, new Document(['$id' => 'landing', 'name' => 'Landing']), 'site', 'waiting', '', ['type' => 'logs'], '');
        $comment->addBuild($project, new Document(['$id' => 'billing', 'name' => 'Billing']), 'function', 'waiting', '', ['type' => 'logs'], '');
        $comment->addBuild($project, new Document(['$id' => 'hello', 'name' => 'Hello']), 'function', 'ready', 'dep1', ['type' => 'logs'], '');

        $text = $comment->generateComment();

        $this->assertStringNotContainsString('/deployments/)', $text);
        $this->assertSame(1, \substr_count($text, '[View Logs]'));
        $this->assertStringContainsString('[View Logs](https://cloud.appwrite.io/console/projects/project1/functions/hello/deployments/dep1)', $text);
    }

    public function testBackwardCompatibilityWithOldStateFormat(): void
    {
        $oldBuilds = [
            'project1_func1' => [
                'projectName' => 'Test Project',
                'projectId' => 'project1',
                'region' => 'default',
                'resourceName' => 'Test Function',
                'resourceId' => 'func1',
                'resourceType' => 'function',
                'buildStatus' => 'ready',
                'deploymentId' => 'dep1',
                'action' => ['type' => 'logs'],
                'previewUrl' => '',
            ],
        ];

        $oldState = '[appwrite]: #' . \base64_encode(\json_encode($oldBuilds)) . "\n\n";
        $oldState .= "> [!TIP]\n> Old tip that should be ignored\n\n";

        $comment = new Comment(['consoleHostname' => 'localhost']);
        $comment->parseComment($oldState);

        $new = $comment->generateComment();
        $newTip = $this->extractTip($new);

        $this->assertNotNull($newTip);
        $this->assertNotSame('Old tip that should be ignored', $newTip);
    }

    public function testParseOldStateFormatWithOnlyBuilds(): void
    {
        $oldBuilds = [
            'project1_func1' => [
                'projectName' => 'Test Project',
                'projectId' => 'project1',
                'region' => 'default',
                'resourceName' => 'Test Function',
                'resourceId' => 'func1',
                'resourceType' => 'function',
                'buildStatus' => 'ready',
                'deploymentId' => 'dep1',
                'action' => ['type' => 'logs'],
                'previewUrl' => '',
            ],
        ];

        $state = '[appwrite]: #' . \base64_encode(\json_encode($oldBuilds)) . "\n\n";

        $comment = new Comment(['consoleHostname' => 'localhost']);
        $comment->parseComment($state);

        $this->assertEquals(false, $comment->isEmpty());

        $first = $comment->generateComment();
        $firstTip = $this->extractTip($first);

        $this->assertNotNull($firstTip);
        $this->assertNotEmpty($firstTip);

        $second = $comment->generateComment();
        $secondTip = $this->extractTip($second);

        $this->assertSame($firstTip, $secondTip);
    }

    public function testSiteStatusImagesMatchExistingFiles(): void
    {
        $extensions = [
            'waiting' => 'status-waiting-%s.png',
            'processing' => 'status-building-%s.gif',
            'building' => 'status-building-%s.gif',
            'ready' => 'status-ready-%s.png',
            'failed' => 'status-failed-%s.png',
        ];

        foreach ($extensions as $status => $file) {
            $comment = new Comment(['consoleHostname' => 'localhost']);
            $comment->addBuild(
                new Document(['$id' => 'project1', 'name' => 'Test Project', 'region' => 'default']),
                new Document(['$id' => 'site1', 'name' => 'Test Site']),
                'site',
                $status,
                'dep1',
                ['type' => 'logs'],
                'https://example.appwrite.network'
            );

            $generated = $comment->generateComment();

            foreach (['light', 'dark'] as $scheme) {
                $path = \sprintf($file, $scheme);

                $this->assertStringContainsString('/images/vcs/' . $path, $generated);
                $this->assertFileExists(__DIR__ . '/../../../public/images/vcs/' . $path);
            }
        }
    }

    public function testEveryDetailsBlockClosesOnce(): void
    {
        $comment = new Comment(['consoleHostname' => 'localhost']);
        foreach (['production' => 'Production', 'staging' => 'Staging'] as $projectId => $projectName) {
            $project = new Document(['$id' => $projectId, 'name' => $projectName, 'region' => 'default']);
            $comment->addBuild($project, new Document(['$id' => 'landing', 'name' => 'Landing']), 'site', 'ready', 'dep-' . $projectId, ['type' => 'logs'], 'https://landing.appwrite.network');
            $comment->addBuild($project, new Document(['$id' => 'billing', 'name' => 'Billing']), 'function', 'processing', 'dep-fn-' . $projectId, ['type' => 'logs'], '');
        }

        $generated = $comment->generateComment();

        // Sites and Functions of both projects, each opened and closed once
        $this->assertSame(4, \substr_count($generated, '<details'));
        $this->assertSame(4, \substr_count($generated, '</details>'));
    }

    private function extractTip(string $comment): ?string
    {
        if (\preg_match('/> \[!TIP\]\n> (.+)/', $comment, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
