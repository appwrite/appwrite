<?php

namespace Utopia\Agents\DiffCheck;

use Utopia\Agents\Adapter;
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\Message;
use Utopia\Agents\Roles\User;

class DiffCheck
{
    /**
     * @param  Agent|Adapter  $runner  Agent (preconfigured) or Adapter (wrapped into a new Agent)
     * @param  Repository|string  $base  Local path or remote URL
     * @param  Repository|string  $target  Local path or remote URL
     * @param  string  $prompt  User prompt. Supports placeholders: {{diff}}, {{base}}, {{target}}, {{diff_stats}}
     * @return array{hasChanges: bool, response: string}
     */
    public function run(
        Agent|Adapter $runner,
        Repository|string $base,
        Repository|string $target,
        string $prompt,
        ?Options $options = null
    ): array {
        $options ??= new Options();
        $baseRepo = Repository::from($base);
        $targetRepo = Repository::from($target);

        $basePath = null;
        $targetPath = null;

        try {
            $basePath = $this->materializeRepository($baseRepo, 'base');
            $targetPath = $this->materializeRepository($targetRepo, 'target');

            $this->applyExcludes($basePath, $options->getExcludePaths());
            $this->applyExcludes($targetPath, $options->getExcludePaths());

            $diffResult = $this->generateDiff($basePath, $targetPath, $options);

            if (! $diffResult['hasChanges']) {
                return [
                    'hasChanges' => false,
                    'response' => '',
                ];
            }

            $finalPrompt = $this->buildPrompt(
                prompt: $prompt,
                diff: $diffResult['diff'],
                base: $baseRepo->getSource(),
                target: $targetRepo->getSource(),
                stats: $diffResult['stats']
            );

            $agent = $this->resolveAgent($runner, $options);
            $conversation = new Conversation($agent);
            $response = $conversation
                ->message(new User($options->getUserId()), new Message($finalPrompt))
                ->send()
                ->getContent();

            return [
                'hasChanges' => true,
                'response' => $options->getTrimResponse() ? trim($response) : $response,
            ];
        } finally {
            if ($basePath !== null) {
                $this->deletePath($basePath);
            }
            if ($targetPath !== null) {
                $this->deletePath($targetPath);
            }
        }
    }

    protected function resolveAgent(Agent|Adapter $runner, Options $options): Agent
    {
        if ($runner instanceof Agent) {
            if ($options->getDescription() !== '') {
                $runner->setDescription($options->getDescription());
            }
            if ($options->getInstructions() !== []) {
                $runner->setInstructions($options->getInstructions());
            }
            if ($options->getSchema() !== null) {
                $runner->setSchema($options->getSchema());
            }

            return $runner;
        }

        $agent = new Agent($runner);
        if ($options->getDescription() !== '') {
            $agent->setDescription($options->getDescription());
        }
        if ($options->getInstructions() !== []) {
            $agent->setInstructions($options->getInstructions());
        }
        if ($options->getSchema() !== null) {
            $agent->setSchema($options->getSchema());
        }

        return $agent;
    }

    protected function materializeRepository(Repository $repository, string $suffix): string
    {
        $path = $this->createTempDirectory($suffix);

        if ($repository->isRemote()) {
            $this->cloneRemoteRepository($repository, $path);

            return $path;
        }

        $source = $repository->getSource();
        $command = 'cp -R '.escapeshellarg($source.'/.').' '.escapeshellarg($path).' 2>&1';
        $result = $this->runCommand($command);

        if ($result['code'] !== 0) {
            throw new \RuntimeException('Failed to copy local repository: '.implode("\n", $result['output']));
        }

        if ($repository->getRef() !== null) {
            $checkout = $this->runCommand(
                'git -C '.escapeshellarg($path).' checkout '.escapeshellarg($repository->getRef()).' 2>&1'
            );
            if ($checkout['code'] !== 0) {
                throw new \RuntimeException('Failed to checkout ref "'.$repository->getRef().'": '.implode("\n", $checkout['output']));
            }
        }

        return $path;
    }

    protected function cloneRemoteRepository(Repository $repository, string $destination): void
    {
        $ref = $repository->getRef();
        if ($ref === null) {
            $clone = $this->runCommand(
                'git clone --depth 1 '.escapeshellarg($repository->getSource()).' '.escapeshellarg($destination).' 2>&1'
            );

            if ($clone['code'] !== 0) {
                throw new \RuntimeException('Failed to clone repository: '.implode("\n", $clone['output']));
            }

            return;
        }

        $clone = $this->runCommand(
            'git clone '.escapeshellarg($repository->getSource()).' '.escapeshellarg($destination).' 2>&1'
        );
        if ($clone['code'] !== 0) {
            throw new \RuntimeException('Failed to clone repository: '.implode("\n", $clone['output']));
        }

        $checkout = $this->runCommand(
            'git -C '.escapeshellarg($destination).' checkout '.escapeshellarg($ref).' 2>&1'
        );
        if ($checkout['code'] !== 0) {
            throw new \RuntimeException('Failed to checkout ref "'.$ref.'": '.implode("\n", $checkout['output']));
        }
    }

    /**
     * @return array{
     *     hasChanges: bool,
     *     diff: string,
     *     stats: string
     * }
     */
    protected function generateDiff(string $basePath, string $targetPath, Options $options): array
    {
        $flags = [];
        if ($options->getIgnoreAllSpace()) {
            $flags[] = '--ignore-all-space';
        }
        if ($options->getIgnoreBlankLines()) {
            $flags[] = '--ignore-blank-lines';
        }

        $command = 'git diff --quiet --no-index '
            .implode(' ', $flags)
            .' -- '
            .escapeshellarg($basePath).' '
            .escapeshellarg($targetPath)
            .' 2>&1';

        $result = $this->runCommand($command);

        if (! in_array($result['code'], [0, 1], true)) {
            throw new \RuntimeException('Failed to generate diff: '.implode("\n", $result['output']));
        }

        if ($result['code'] === 0) {
            return [
                'hasChanges' => false,
                'diff' => '',
                'stats' => '0 lines, 0 bytes',
            ];
        }

        $maxLines = $options->getMaxDiffLines();
        $captureLines = $maxLines + 1;
        $outputResult = $this->runCommand(
            '(git diff --no-index '.implode(' ', $flags).' -- '.escapeshellarg($basePath).' '.escapeshellarg($targetPath).') 2>&1'
            .' | head -n '
            .(string) $captureLines
        );

        $lines = $outputResult['output'];
        $capturedLineCount = count($lines);
        $truncated = $capturedLineCount > $maxLines;
        if ($truncated) {
            $lines = array_slice($lines, 0, $maxLines);
        }

        $diff = implode("\n", $lines);
        if ($truncated) {
            $diff .= "\n\n[Diff truncated to {$maxLines} lines.]";
        }

        return [
            'hasChanges' => true,
            'diff' => $diff,
            'stats' => ($truncated ? '>='.$maxLines : (string) $capturedLineCount).' lines, '.strlen($diff).' bytes'.($truncated ? ', truncated' : ''),
        ];
    }

    protected function buildPrompt(
        string $prompt,
        string $diff,
        string $base,
        string $target,
        string $stats
    ): string {
        $replacements = [
            '{{diff}}' => $diff,
            '{{base}}' => $base,
            '{{target}}' => $target,
            '{{diff_stats}}' => $stats,
        ];

        $finalPrompt = strtr($prompt, $replacements);

        if (! str_contains($prompt, '{{diff}}')) {
            $finalPrompt .= "\n\nGit diff:\n```diff\n{$diff}\n```";
        }

        return $finalPrompt;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    protected function applyExcludes(string $root, array $patterns): void
    {
        if ($patterns === []) {
            return;
        }

        $normalized = [];
        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', $pattern));
            if ($pattern === '') {
                continue;
            }
            $normalized[] = ltrim($pattern, '/');
        }

        if ($normalized === []) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            if ($relative === '') {
                continue;
            }

            foreach ($normalized as $pattern) {
                if ($this->matchesGlob($relative, $pattern)) {
                    if (is_dir($path)) {
                        $this->deletePath($path);
                    } else {
                        @unlink($path);
                    }
                    break;
                }
            }
        }
    }

    protected function matchesGlob(string $path, string $pattern): bool
    {
        $path = trim($path, '/');
        $pattern = trim($pattern, '/');

        if ($pattern === '') {
            return false;
        }

        if (str_ends_with($pattern, '/**') && $path === substr($pattern, 0, -3)) {
            return true;
        }

        $escaped = preg_quote($pattern, '/');
        $escaped = str_replace('\*\*', '::DOUBLE_WILDCARD::', $escaped);
        $escaped = str_replace('\*', '[^/]*', $escaped);
        $escaped = str_replace('\?', '[^/]', $escaped);
        $escaped = str_replace('::DOUBLE_WILDCARD::', '.*', $escaped);

        return (bool) preg_match('/^'.$escaped.'$/', $path);
    }

    protected function createTempDirectory(string $suffix): string
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'utopia-diff-check-'
            .$suffix
            .'-'
            .bin2hex(random_bytes(8));

        if (! mkdir($path, 0777, true) && ! is_dir($path)) {
            throw new \RuntimeException('Failed to create temp directory: '.$path);
        }

        return $path;
    }

    /**
     * @return array{code: int, output: array<int, string>}
     */
    protected function runCommand(string $command): array
    {
        $output = [];
        $code = 0;
        exec($command, $output, $code);

        return [
            'code' => $code,
            'output' => $output,
        ];
    }

    protected function deletePath(string $path): void
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
