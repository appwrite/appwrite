<?php

namespace Tests\Utopia\Agents\DiffCheck;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\DiffCheck\DiffCheck;
use Utopia\Agents\DiffCheck\Options;
use Utopia\Agents\Schema;
use Utopia\Agents\Schema\SchemaObject;

class DiffCheckOpenAITest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().'/utopia-diff-check-openai-tests-'.bin2hex(random_bytes(8));
        mkdir($this->tempRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempRoot) && is_dir($this->tempRoot)) {
            $this->deletePath($this->tempRoot);
        }

        parent::tearDown();
    }

    /**
     * @group integration
     */
    public function testRunWithOpenAIGpt5Nano(): void
    {
        $apiKey = getenv('LLM_KEY_OPENAI');
        if ($apiKey === false || trim($apiKey) === '') {
            $this->markTestSkipped('LLM_KEY_OPENAI environment variable is not set');
        }

        $base = $this->createFixtureDirectory('base', [
            'README.md' => "Hello\n",
            '.github/workflows/build.yml' => "name: build\n",
        ]);
        $target = $this->createFixtureDirectory('target', [
            'README.md' => "Hello world\n",
            '.github/workflows/build.yml' => "name: build-updated\n",
        ]);

        $adapter = new OpenAI(
            apiKey: $apiKey,
            model: OpenAI::MODEL_GPT_5_NANO,
            maxTokens: 2048,
            temperature: 1.0
        );

        $schemaObject = new SchemaObject();
        $schemaObject->addProperty('summary', [
            'type' => SchemaObject::TYPE_STRING,
            'description' => 'One concise sentence about user-facing diff changes.',
        ]);
        $schema = new Schema(
            name: 'diff_check_openai_test',
            description: 'Return concise summary for changed SDK diff',
            object: $schemaObject,
            required: $schemaObject->getNames()
        );

        $options = (new Options)
            ->setSchema($schema)
            ->setExcludePaths(['.github'])
            ->setDescription('You analyze code diffs and return concise structured output.');

        $result = (new DiffCheck)->run(
            runner: $adapter,
            base: $base,
            target: $target,
            prompt: "Summarize the user-facing change from this diff.\n\n{{diff}}",
            options: $options
        );

        $this->assertTrue($result['hasChanges']);
        $this->assertNotSame('', trim($result['response']), 'Response was empty. Raw response: '.var_export($result['response'], true));

        $decoded = json_decode($result['response'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertIsString($decoded['summary']);
        $this->assertNotSame('', trim($decoded['summary']));
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
