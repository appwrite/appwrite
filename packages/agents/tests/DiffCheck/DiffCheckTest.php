<?php

namespace Utopia\Agents\Tests\DiffCheck;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Agent;
use Utopia\Agents\DiffCheck\DiffCheck;
use Utopia\Agents\DiffCheck\Options;
use Utopia\Agents\FinishReason;
use Utopia\Agents\Parts\Text;
use Utopia\Agents\Response;
use Utopia\Agents\Schema;
use Utopia\Agents\Tests\Fakes\Model;

class DiffCheckTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir().'/utopia-diff-check-tests-'.bin2hex(random_bytes(8));
        mkdir($this->tempRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempRoot) && is_dir($this->tempRoot)) {
            $this->deletePath($this->tempRoot);
        }

        parent::tearDown();
    }

    public function testRunSkipsAiWhenThereIsNoDiff(): void
    {
        $base = $this->createFixtureDirectory('base-no-diff', [
            'README.md' => "Hello\n",
        ]);
        $target = $this->createFixtureDirectory('target-no-diff', [
            'README.md' => "Hello\n",
        ]);

        $adapter = $this->model('should-not-be-called');
        $result = (new DiffCheck())->run(
            runner: $adapter,
            base: $base,
            target: $target,
            prompt: 'Analyze this diff: {{diff}}',
            options: new Options()
        );

        $this->assertFalse($result['hasChanges']);
        $this->assertSame('', $result['response']);
        $this->assertSame(0, \count($adapter->requests));
    }

    public function testRunReturnsResponseWhenDiffExists(): void
    {
        $base = $this->createFixtureDirectory('base-diff', [
            'README.md' => "Hello\n",
        ]);
        $target = $this->createFixtureDirectory('target-diff', [
            'README.md' => "Hello world\n",
        ]);

        $adapter = $this->model('{"version":"1.2.3"}');
        $result = (new DiffCheck())->run(
            runner: $adapter,
            base: $base,
            target: $target,
            prompt: 'Please inspect:\n{{diff}}',
            options: new Options()
        );

        $this->assertTrue($result['hasChanges']);
        $this->assertSame('{"version":"1.2.3"}', $result['response']);
        $this->assertSame(1, \count($adapter->requests));
        $this->assertStringContainsString('Hello world', $adapter->requests[0]->messages[0]->text());
    }

    public function testRunSupportsAgentRunner(): void
    {
        $base = $this->createFixtureDirectory('base-agent', [
            'a.txt' => "one\n",
        ]);
        $target = $this->createFixtureDirectory('target-agent', [
            'a.txt' => "two\n",
        ]);

        $adapter = $this->model('agent-runner-ok');
        $agent = new Agent($adapter);
        $agent->setInstructions('Return the raw result');

        $result = (new DiffCheck())->run(
            runner: $agent,
            base: $base,
            target: $target,
            prompt: 'Analyze: {{diff}}',
            options: new Options()
        );

        $this->assertTrue($result['hasChanges']);
        $this->assertSame('agent-runner-ok', $result['response']);
        $this->assertSame(1, \count($adapter->requests));
        $this->assertSame('Return the raw result', $adapter->requests[0]->system());
    }

    public function testRunTruncatesDiffByLineCount(): void
    {
        $baseLines = [];
        $targetLines = [];
        for ($i = 1; $i <= 25; $i++) {
            $baseLines[] = 'old line '.$i;
            $targetLines[] = 'new line '.$i;
        }

        $base = $this->createFixtureDirectory('base-truncate', [
            'long.txt' => implode("\n", $baseLines)."\n",
        ]);
        $target = $this->createFixtureDirectory('target-truncate', [
            'long.txt' => implode("\n", $targetLines)."\n",
        ]);

        $adapter = $this->model('ok');
        $options = (new Options())
            ->setMaxDiffLines(8);

        $result = (new DiffCheck())->run(
            runner: $adapter,
            base: $base,
            target: $target,
            prompt: 'Analyze the diff\n{{diff}}',
            options: $options
        );

        $this->assertTrue($result['hasChanges']);
        $this->assertSame('ok', $result['response']);
        $this->assertStringContainsString('[Diff truncated to 8 lines.]', $adapter->requests[0]->messages[0]->text());
    }

    public function testRunCanExcludePathsFromDiff(): void
    {
        $base = $this->createFixtureDirectory('base-exclude', [
            '.github/workflows/build.yml' => "name: build\n",
            'src/main.txt' => "same\n",
        ]);
        $target = $this->createFixtureDirectory('target-exclude', [
            '.github/workflows/build.yml' => "name: build-updated\n",
            'src/main.txt' => "same\n",
        ]);

        $adapter = $this->model('should-not-run');
        $options = (new Options())
            ->setExcludePaths(['.github']);

        $result = (new DiffCheck())->run(
            runner: $adapter,
            base: $base,
            target: $target,
            prompt: 'Analyze {{diff}}',
            options: $options
        );

        $this->assertFalse($result['hasChanges']);
        $this->assertSame('', $result['response']);
        $this->assertSame(0, \count($adapter->requests));
    }

    public function testRunDecodesStructuredOutput(): void
    {
        $base = $this->createFixtureDirectory('base-schema', [
            'composer.json' => "{\"version\": \"1.0.0\"}\n",
        ]);
        $target = $this->createFixtureDirectory('target-schema', [
            'composer.json' => "{\"version\": \"1.1.0\"}\n",
        ]);

        $adapter = $this->model('{"bump":"minor"}');
        $options = (new Options())
            ->setInstructions('Classify the version bump')
            ->setSchema(Schema::object(['bump' => Schema::enum(['major', 'minor', 'patch'])]));

        $result = (new DiffCheck())->run(
            runner: $adapter,
            base: $base,
            target: $target,
            prompt: 'Analyze {{diff}}',
            options: $options
        );

        $this->assertSame(['bump' => 'minor'], $result['output']);
        $this->assertNotNull($adapter->requests[0]->schema);
        $this->assertSame('Classify the version bump', $adapter->requests[0]->system());
    }

    private function model(string $text): Model
    {
        return new Model([new Response([new Text($text)], FinishReason::Stop)]);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function createFixtureDirectory(string $name, array $files): string
    {
        $root = $this->tempRoot.'/'.$name;
        mkdir($root, 0777, true);

        foreach ($files as $relative => $content) {
            $path = $root.'/'.$relative;
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($path, $content);
        }

        return $root;
    }

    private function deletePath(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
