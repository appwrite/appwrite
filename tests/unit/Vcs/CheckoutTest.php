<?php

declare(strict_types=1);

namespace Tests\Unit\Vcs;

use Appwrite\Vcs\Checkout;
use Appwrite\Vcs\Checkout\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckoutTest extends TestCase
{
    private string $directory;

    private string $checkout;

    private string $outside;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/checkout-test-' . \bin2hex(\random_bytes(8));
        $this->checkout = $this->directory . '/checkout';
        $this->outside = $this->directory . '/outside';

        \mkdir($this->checkout . '/functions/api', recursive: true);
        \mkdir($this->outside . '/api', recursive: true);
        \file_put_contents($this->checkout . '/README.md', 'readme');
        \file_put_contents($this->outside . '/secret', 'secret');
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->directory));
    }

    public function testResolvesNestedDirectory(): void
    {
        $resolved = (new Checkout($this->checkout))->resolve('functions/api');

        $this->assertSame(\realpath($this->checkout) . '/functions/api', $resolved);
    }

    public function testResolvesRootForEmptyAndCurrentDirectory(): void
    {
        $checkout = new Checkout($this->checkout);

        $this->assertSame(\realpath($this->checkout), $checkout->resolve(''));
        $this->assertSame(\realpath($this->checkout), $checkout->resolve('./'));
    }

    public function testCreatesMissingDirectoriesWhenAsked(): void
    {
        $resolved = (new Checkout($this->checkout))->resolve('functions/new/handler', create: true);

        $this->assertDirectoryExists($resolved);
        $this->assertSame(\realpath($this->checkout) . '/functions/new/handler', $resolved);
    }

    public function testRefusesMissingDirectoryWithoutCreate(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Root directory "functions/missing" not found in the repository.');

        (new Checkout($this->checkout))->resolve('functions/missing');
    }

    public function testRefusesFile(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Root directory "README.md" must be a directory.');

        (new Checkout($this->checkout))->resolve('README.md');
    }

    public function testRefusesMissingCheckout(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Repository checkout is missing.');

        (new Checkout($this->directory . '/absent'))->resolve('functions');
    }

    public function testRefusesParentSegment(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Root directory must stay inside the repository.');

        (new Checkout($this->checkout))->resolve('functions/../../outside');
    }

    /**
     * @return \Iterator<string, array{string, string, bool}>
     */
    public static function provideLinks(): \Iterator
    {
        yield 'linked root directory' => ['linked', 'linked', false];
        yield 'linked parent directory' => ['linked', 'linked/api', false];
        yield 'linked nested directory' => ['functions/linked', 'functions/linked', false];
        yield 'linked root directory with create' => ['linked', 'linked/new', true];
    }

    #[DataProvider('provideLinks')]
    public function testRefusesSymbolicLinks(string $link, string $directory, bool $create): void
    {
        \symlink($this->outside, $this->checkout . '/' . $link);

        try {
            (new Checkout($this->checkout))->resolve($directory, $create);
            $this->fail('Resolved "' . $directory . '" through a symbolic link out of the checkout.');
        } catch (Exception $exception) {
            $this->assertSame('Root directory "' . $directory . '" must not be a symbolic link.', $exception->getMessage());
        }

        $this->assertSame(['.', '..', 'api', 'secret'], \scandir($this->outside), 'The link target was modified.');
    }
}
