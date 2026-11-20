<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Vcs\Comment;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class CommentTest extends TestCase
{
    public function testCommitIsNamedForSitesAndFunctions(): void
    {
        $comment = new Comment(['consoleHostname' => 'localhost']);
        $project = new Document(['$id' => 'project1', 'name' => 'Test Project', 'region' => 'default']);
        $comment->addBuild($project, new Document(['$id' => 'landing', 'name' => 'Landing']), 'site', 'ready', 'dep1', ['type' => 'logs'], 'https://landing.appwrite.network', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');
        $comment->addBuild($project, new Document(['$id' => 'billing', 'name' => 'Billing']), 'function', 'building', 'dep2', ['type' => 'logs'], '', '4e7a91cd02b6f83a5d1c9e0b7f24a6830c5d19ab');

        $text = $comment->generateComment();

        $this->assertStringContainsString('| **Landing** | `cc9b013` | ', $text);
        $this->assertStringContainsString('| **Billing** | `4e7a91c` | ', $text);
        $this->assertStringNotContainsString('cc9b0134', $text);
    }

    public function testCommitIsRestoredFromParsedComment(): void
    {
        $project = new Document(['$id' => 'project1', 'name' => 'Test Project', 'region' => 'default']);
        $comment = new Comment(['consoleHostname' => 'localhost']);
        $comment->addBuild($project, new Document(['$id' => 'func1', 'name' => 'Test Function']), 'function', 'ready', 'dep1', ['type' => 'logs'], '', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');

        $parsed = new Comment(['consoleHostname' => 'localhost']);
        $parsed->parseComment($comment->generateComment());
        $parsed->addBuild($project, new Document(['$id' => 'func2', 'name' => 'Another Function']), 'function', 'building', 'dep2', ['type' => 'logs'], '', '4e7a91cd02b6f83a5d1c9e0b7f24a6830c5d19ab');

        $text = $parsed->generateComment();

        $this->assertStringContainsString('| **Test Function** | `cc9b013` | ', $text);
        $this->assertStringContainsString('| **Another Function** | `4e7a91c` | ', $text);
    }

    public function testSitesAndFunctionsShareRowShape(): void
    {
        $comment = new Comment(['consoleUrl' => 'https://cloud.appwrite.io/console', 'consoleHostname' => 'cloud.appwrite.io'], false);
        $project = new Document(['$id' => 'project1', 'name' => 'Test Project', 'region' => 'default']);
        $comment->addBuild($project, new Document(['$id' => 'landing', 'name' => 'Landing']), 'site', 'ready', 'dep1', ['type' => 'logs'], 'https://landing.appwrite.network', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');
        $comment->addBuild($project, new Document(['$id' => 'billing', 'name' => 'Billing']), 'function', 'ready', 'dep2', ['type' => 'logs'], '', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');

        $text = $comment->generateComment();

        // Name, commit and status first, the Console link last; a site adds its preview in between
        $this->assertStringContainsString("| Site | Commit | Status | Preview | QR | Details |\n", $text);
        $this->assertStringContainsString("| Function | Commit | Status | Details |\n", $text);
        $this->assertStringContainsString('| **Landing** | `cc9b013` |  _Ready_ | [Preview URL](https://landing.appwrite.network) | [QR Code](https://cloud.appwrite.io/v1/avatars/qr?text=https%3A%2F%2Flanding.appwrite.network) | [View logs](https://cloud.appwrite.io/console/projects/project1/sites/landing/deployments/dep1) |', $text);
        $this->assertStringContainsString('| **Billing** | `cc9b013` |  _Ready_ | [View logs](https://cloud.appwrite.io/console/projects/project1/functions/billing/deployments/dep2) |', $text);
    }

    public function testCanceledBuildShowsItsStatus(): void
    {
        $comment = new Comment(['consoleHostname' => 'localhost']);
        $project = new Document(['$id' => 'project1', 'name' => 'Test Project', 'region' => 'default']);
        $comment->addBuild($project, new Document(['$id' => 'landing', 'name' => 'Landing']), 'site', 'canceled', 'dep1', ['type' => 'logs'], 'https://landing.appwrite.network', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');
        $comment->addBuild($project, new Document(['$id' => 'billing', 'name' => 'Billing']), 'function', 'canceled', 'dep2', ['type' => 'logs'], '', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');

        $text = $comment->generateComment();

        $this->assertSame(2, \substr_count($text, '| `cc9b013` | _Canceled_ | '));
        // No image exists for the state, so none is linked
        $this->assertStringNotContainsString('status-canceled', $text);
    }

    public function testHeadingNamesTheCommentOnce(): void
    {
        $comment = new Comment(['consoleHostname' => 'localhost']);
        foreach (['production' => 'Production', 'staging' => 'Staging'] as $projectId => $projectName) {
            $project = new Document(['$id' => $projectId, 'name' => $projectName, 'region' => 'default']);
            $comment->addBuild($project, new Document(['$id' => 'landing', 'name' => 'Landing']), 'site', 'ready', 'dep-' . $projectId, ['type' => 'logs'], 'https://landing.appwrite.network', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');
        }

        $text = $comment->generateComment();

        $this->assertSame(1, \substr_count($text, "## Deployments\n"));
        $this->assertSame(1, \substr_count($text, '## '));
        $this->assertStringContainsString("Project **Production** · `production`\n", $text);
        $this->assertStringContainsString("Project **Staging** · `staging`\n", $text);
    }

    public function testTipLinksGitSettingsOfOneResource(): void
    {
        $comment = new Comment(['consoleUrl' => 'https://cloud.appwrite.io/console', 'consoleHostname' => 'cloud.appwrite.io']);
        $project = new Document(['$id' => 'project1', 'name' => 'Test Project', 'region' => 'default']);
        $comment->addBuild($project, new Document(['$id' => 'billing', 'name' => 'Billing']), 'function', 'ready', 'dep1', ['type' => 'logs'], '', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');

        $text = $comment->generateComment();

        $this->assertStringContainsString("> [!TIP]\n> Push a commit to redeploy, or switch these comments off in [Git automation](https://cloud.appwrite.io/console/projects/project1/functions/billing/settings/git).\n", $text);
    }

    public function testTipNamesGitSettingsForSeveralResources(): void
    {
        $comment = new Comment(['consoleUrl' => 'https://cloud.appwrite.io/console', 'consoleHostname' => 'cloud.appwrite.io']);
        $project = new Document(['$id' => 'project1', 'name' => 'Test Project', 'region' => 'default']);
        $comment->addBuild($project, new Document(['$id' => 'landing', 'name' => 'Landing']), 'site', 'ready', 'dep1', ['type' => 'logs'], 'https://landing.appwrite.network', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');
        $comment->addBuild($project, new Document(['$id' => 'billing', 'name' => 'Billing']), 'function', 'ready', 'dep2', ['type' => 'logs'], '', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');

        $text = $comment->generateComment();

        $this->assertStringContainsString("> [!TIP]\n> Push a commit to redeploy, or switch these comments off under **Git automation** in the settings of each site or function.\n", $text);
        $this->assertStringNotContainsString('/settings/git', $text);
    }

    public function testBuildWithoutDeploymentLinksNoLogs(): void
    {
        // A pull request can open on a commit nothing deployed yet
        $comment = new Comment(['consoleUrl' => 'https://cloud.appwrite.io/console', 'consoleHostname' => 'cloud.appwrite.io']);
        $project = new Document(['$id' => 'project1', 'name' => 'Test Project', 'region' => 'default']);
        $comment->addBuild($project, new Document(['$id' => 'landing', 'name' => 'Landing']), 'site', 'waiting', '', ['type' => 'logs'], '', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');
        $comment->addBuild($project, new Document(['$id' => 'billing', 'name' => 'Billing']), 'function', 'waiting', '', ['type' => 'logs'], '', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');
        $comment->addBuild($project, new Document(['$id' => 'hello', 'name' => 'Hello']), 'function', 'ready', 'dep1', ['type' => 'logs'], '', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');

        $text = $comment->generateComment();

        $this->assertStringNotContainsString('/deployments/)', $text);
        $this->assertSame(1, \substr_count($text, '[View logs]'));
        $this->assertStringContainsString('[View logs](https://cloud.appwrite.io/console/projects/project1/functions/hello/deployments/dep1)', $text);
    }

    public function testStateWithTipIsStillParsed(): void
    {
        // The state carried the comment's random tip before the tip became one sentence
        $builds = [
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

        $state = '[appwrite]: #' . \base64_encode(\json_encode(['builds' => $builds, 'tip' => 'Old tip that should be ignored'])) . "\n\n";
        $state .= "> [!TIP]\n> Old tip that should be ignored\n\n";

        $comment = new Comment(['consoleHostname' => 'localhost']);
        $comment->parseComment($state);

        $text = $comment->generateComment();

        $this->assertStringNotContainsString('Old tip that should be ignored', $text);
        // The build was saved before commits were named, so its cell stays empty
        $this->assertStringContainsString('| **Test Function** |  | ', $text);
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
            ],
        ];

        $state = '[appwrite]: #' . \base64_encode(\json_encode($oldBuilds)) . "\n\n";

        $comment = new Comment(['consoleHostname' => 'localhost']);
        $comment->parseComment($state);

        $this->assertEquals(false, $comment->isEmpty());
        $this->assertStringContainsString('| **Test Function** |  | ', $comment->generateComment());
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
                'https://example.appwrite.network',
                'cc9b0134304a79ee544b145f9ed3c1ca96e79b05'
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
            $comment->addBuild($project, new Document(['$id' => 'landing', 'name' => 'Landing']), 'site', 'ready', 'dep-' . $projectId, ['type' => 'logs'], 'https://landing.appwrite.network', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');
            $comment->addBuild($project, new Document(['$id' => 'billing', 'name' => 'Billing']), 'function', 'processing', 'dep-fn-' . $projectId, ['type' => 'logs'], '', 'cc9b0134304a79ee544b145f9ed3c1ca96e79b05');
        }

        $generated = $comment->generateComment();

        // Sites and Functions of both projects, each opened and closed once
        $this->assertSame(4, \substr_count($generated, '<details'));
        $this->assertSame(4, \substr_count($generated, '</details>'));
    }
}
