<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

use PHPUnit\Framework\TestCase;

final class GitTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vcs-checkout-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/checkout', 0777, true);
        mkdir($this->root . '/outside');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testWritesInsideTheCheckout(): void
    {
        new CheckoutWriter()->write($this->root . '/checkout', 'docs/./guide.md', 'content');

        $this->assertSame('content', file_get_contents($this->root . '/checkout/docs/guide.md'));
    }

    public function testRefusesASymlinkedDirectory(): void
    {
        symlink($this->root . '/outside', $this->root . '/checkout/linked');

        try {
            new CheckoutWriter()->write($this->root . '/checkout', 'linked/escape.txt', 'content');
            $this->fail('A write through a linked directory must be refused');
        } catch (\Exception $error) {
            $this->assertStringContainsString('escapes the repository', $error->getMessage());
        }

        $this->assertFileDoesNotExist($this->root . '/outside/escape.txt');
    }

    public function testRefusesASymlinkedParentBeforeCreatingAnythingBeneathIt(): void
    {
        symlink($this->root . '/outside', $this->root . '/checkout/linked');

        try {
            new CheckoutWriter()->write($this->root . '/checkout', 'linked/new/escape.txt', 'content');
            $this->fail('A write beneath a linked directory must be refused');
        } catch (\Exception $error) {
            $this->assertStringContainsString('escapes the repository', $error->getMessage());
        }

        $this->assertSame([], array_values(array_diff(scandir($this->root . '/outside') ?: [], ['.', '..'])));
    }

    public function testRefusesASymlinkedFile(): void
    {
        file_put_contents($this->root . '/outside/target.txt', 'original');
        symlink($this->root . '/outside/target.txt', $this->root . '/checkout/linked.txt');

        try {
            new CheckoutWriter()->write($this->root . '/checkout', 'linked.txt', 'content');
            $this->fail('A write through a linked file must be refused');
        } catch (\Exception $error) {
            $this->assertStringContainsString('escapes the repository', $error->getMessage());
        }

        $this->assertSame('original', file_get_contents($this->root . '/outside/target.txt'));
    }
}
