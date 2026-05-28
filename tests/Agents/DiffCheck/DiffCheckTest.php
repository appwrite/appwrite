<?php

namespace Tests\Utopia\Agents\DiffCheck;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapter;
use Utopia\Agents\Agent;
use Utopia\Agents\DiffCheck\DiffCheck;
use Utopia\Agents\DiffCheck\Options;
use Utopia\Agents\Message;

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

        $adapter = new FakeAdapter('should-not-be-called');
        $result = (new DiffCheck)->run(
            runner: $adapter,
            base: $base,
            target: $target,
            prompt: 'Analyze this diff: {{diff}}',
            options: new Options
        );

        $this->assertFalse($result['hasChanges']);
        $this->assertSame('', $result['response']);
        $this->assertSame(0, $adapter->sendCalls);
    }

    public function testRunReturnsResponseWhenDiffExists(): void
    {
        $base = $this->createFixtureDirectory('base-diff', [
            'README.md' => "Hello\n",
        ]);
        $target = $this->createFixtureDirectory('target-diff', [
            'README.md' => "Hello world\n",
        ]);

        $adapter = new FakeAdapter('{"version":"1.2.3"}');
        $result = (new DiffCheck)->run(
            runner: $adapter,
            base: $base,
            target: $target,
            prompt: 'Please inspect:\n{{diff}}',
            options: new Options
        );

        $this->assertTrue($result['hasChanges']);
        $this->assertSame('{"version":"1.2.3"}', $result['response']);
        $this->assertSame(1, $adapter->sendCalls);
        $this->assertNotNull($adapter->lastUserPrompt);
        $this->assertStringContainsString('Hello world', $adapter->lastUserPrompt ?? '');
    }

    public function testRunSupportsAgentRunner(): void
    {
        $base = $this->createFixtureDirectory('base-agent', [
            'a.txt' => "one\n",
        ]);
        $target = $this->createFixtureDirectory('target-agent', [
            'a.txt' => "two\n",
        ]);

        $adapter = new FakeAdapter('agent-runner-ok');
        $agent = new Agent($adapter);
        $agent->setInstructions([
            'description' => 'Return the raw result',
        ]);

        $result = (new DiffCheck)->run(
            runner: $agent,
            base: $base,
            target: $target,
            prompt: 'Analyze: {{diff}}',
            options: new Options
        );

        $this->assertTrue($result['hasChanges']);
        $this->assertSame('agent-runner-ok', $result['response']);
        $this->assertSame(1, $adapter->sendCalls);
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

        $adapter = new FakeAdapter('ok');
        $options = (new Options)
            ->setMaxDiffLines(8);

        $result = (new DiffCheck)->run(
            runner: $adapter,
            base: $base,
            target: $target,
            prompt: 'Analyze the diff\n{{diff}}',
            options: $options
        );

        $this->assertTrue($result['hasChanges']);
        $this->assertSame('ok', $result['response']);
        $this->assertNotNull($adapter->lastUserPrompt);
        $this->assertStringContainsString('[Diff truncated to 8 lines.]', $adapter->lastUserPrompt ?? '');
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

        $adapter = new FakeAdapter('should-not-run');
        $options = (new Options)
            ->setExcludePaths(['.github']);

        $result = (new DiffCheck)->run(
            runner: $adapter,
            base: $base,
            target: $target,
            prompt: 'Analyze {{diff}}',
            options: $options
        );

        $this->assertFalse($result['hasChanges']);
        $this->assertSame('', $result['response']);
        $this->assertSame(0, $adapter->sendCalls);
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

class FakeAdapter extends Adapter
{
    public int $sendCalls = 0;

    public ?string $lastUserPrompt = null;

    private string $response;

    public function __construct(string $response)
    {
        $this->response = $response;
    }

    public function getName(): string
    {
        return 'fake';
    }

    /**
     * @param  array<Message>  $messages
     */
    public function send(array $messages, ?callable $listener = null): Message
    {
        $this->sendCalls++;
        $last = end($messages);
        if ($last instanceof Message) {
            $this->lastUserPrompt = $last->getContent();
        }

        return new Message($this->response);
    }

    public function getModels(): array
    {
        return ['fake-model'];
    }

    public function getModel(): string
    {
        return 'fake-model';
    }

    public function setModel(string $model): self
    {
        return $this;
    }

    public function isSchemaSupported(): bool
    {
        return true;
    }

    public function getSupportForEmbeddings(): bool
    {
        return false;
    }

    public function embed(string $text): array
    {
        throw new \Exception('Embeddings not supported by fake adapter');
    }

    /**
     * @param  array<int, string>  $texts
     * @return array{
     *     embeddings: array<int, array<int, float>>,
     *     tokensProcessed: int|null,
     *     totalDuration: int|null
     * }
     */
    public function bulkEmbed(array $texts): array
    {
        throw new \Exception('Embeddings not supported by fake adapter');
    }

    public function getEmbeddingDimension(): int
    {
        return 0;
    }

    protected function formatErrorMessage($json): string
    {
        return 'fake error';
    }
}
