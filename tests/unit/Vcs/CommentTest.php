<?php

namespace Tests\Unit\Vcs;

use Appwrite\Vcs\Comment;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

class CommentTest extends TestCase
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

        $this->assertEquals($firstTip, $secondTip);
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
        $this->assertNotEquals('Old tip that should be ignored', $newTip);
        $this->assertContains($newTip, $this->getTips());
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
        $this->assertContains($firstTip, $this->getTips());

        $second = $comment->generateComment();
        $secondTip = $this->extractTip($second);

        $this->assertEquals($firstTip, $secondTip);
    }

    private function extractTip(string $comment): ?string
    {
        if (\preg_match('/> \[!TIP\]\n> (.+)/', $comment, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function getTips(): array
    {
        $reflection = new \ReflectionClass(Comment::class);
        $property = $reflection->getProperty('tips');

        return $property->getValue(new Comment(['consoleHostname' => 'localhost']));
    }
}
