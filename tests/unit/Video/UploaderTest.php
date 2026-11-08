<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use Appwrite\Video\Uploader;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Tests\Unit\Video\Fakes\Store;
use Utopia\Video\Written;

use function Swoole\Coroutine\run;

/**
 * The uploader ships a rendition's segments from its own coroutine while
 * ffmpeg writes them. Each test stages segment files in a temp workspace and
 * a Store on a temp root, then checks what reached the store and what stayed.
 *
 * run() leaves every runtime hook enabled and a reactor behind, so each test
 * gets its own process.
 */
final class UploaderTest extends TestCase
{
    private string $workspace;

    private string $root;

    /** Where the uploader puts segments on the store. */
    private string $path;

    protected function setUp(): void
    {
        $this->workspace = \sys_get_temp_dir() . '/uploader-workspace-' . \uniqid();
        $this->root = \sys_get_temp_dir() . '/uploader-store-' . \uniqid();
        $this->path = $this->root . '/rendition/';
        \mkdir($this->workspace, 0755, true);
        \mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->workspace);
        $this->remove($this->root);
    }

    #[RunInSeparateProcess]
    public function testUploadsEachSegmentInOrderAndRemovesItFromTheWorkspace(): void
    {
        run(function (): void {
            $store = new Store($this->root);
            $uploader = new Uploader($store, $this->path);
            $uploader->start();

            $segments = $this->segments(3);
            foreach ($segments as $written) {
                $uploader->take($written);
            }
            $uploader->close();
            $bytes = $uploader->finish();

            $this->assertSame(600, $bytes);
            $this->assertSame(
                [$this->path . 'segment_1.ts', $this->path . 'segment_2.ts', $this->path . 'segment_3.ts'],
                $store->writes
            );
            foreach ($segments as $written) {
                $this->assertFileExists($this->path . $written->file);
                $this->assertSame($written->size, \filesize($this->path . $written->file));
                $this->assertFileDoesNotExist($written->path);
            }
            $this->assertSame(0, $uploader->pending());
        });
    }

    #[RunInSeparateProcess]
    public function testHandingOverNeverWaitsForASlowStore(): void
    {
        run(function (): void {
            $store = new Store($this->root, delay: 0.05);
            $uploader = new Uploader($store, $this->path);
            $uploader->start();

            $segments = $this->segments(20);
            $start = \microtime(true);
            foreach ($segments as $written) {
                $uploader->take($written);
            }
            $elapsed = \microtime(true) - $start;

            // The store needs a second for these; ffmpeg's loop was not made to wait for it.
            $this->assertLessThan(0.1, $elapsed, 'handing over waited for the store');
            $this->assertGreaterThan(10, $uploader->pending(), 'the store was not behind');

            $uploader->close();
            $this->assertSame(20 * 100 * 21 / 2, $uploader->finish());
            $this->assertCount(20, $store->writes);
            $this->assertSame([], $this->left(), 'segments stayed in the workspace');
            $this->assertSame(0, $uploader->pending());
        });
    }

    #[RunInSeparateProcess]
    public function testAFailedUploadIsThrownAtTheNextHandOver(): void
    {
        run(function (): void {
            $store = new Store($this->root, failAt: 2);
            $uploader = new Uploader($store, $this->path);
            $uploader->start();

            $segments = $this->segments(4);
            $uploader->take($segments[0]);
            Coroutine::sleep(0.01);
            $uploader->take($segments[1]);
            Coroutine::sleep(0.01);

            $thrown = null;
            try {
                $uploader->take($segments[2]);
            } catch (\RuntimeException $th) {
                $thrown = $th;
            }
            $this->assertNotNull($thrown, 'the failed upload was not thrown at the next hand-over');
            $this->assertSame('store down', $thrown->getMessage());

            $uploader->abandon();

            $this->assertSame([$this->path . 'segment_1.ts'], $store->writes);
            $this->assertFileDoesNotExist($segments[0]->path);
            $this->assertSame(['segment_2.ts', 'segment_3.ts', 'segment_4.ts'], $this->left(), 'segments not uploaded must stay for the cleanup');
        });
    }

    #[RunInSeparateProcess]
    public function testAFailureDuringTheDrainIsThrownByFinish(): void
    {
        run(function (): void {
            $store = new Store($this->root, failAt: 3);
            $uploader = new Uploader($store, $this->path);
            $uploader->start();

            $segments = $this->segments(3);
            foreach ($segments as $written) {
                $uploader->take($written);
            }
            $uploader->close();

            $thrown = null;
            try {
                $uploader->finish();
            } catch (\RuntimeException $th) {
                $thrown = $th;
            }
            $this->assertNotNull($thrown, 'the failed upload was not thrown by finish()');
            $this->assertSame('store down', $thrown->getMessage());

            $this->assertSame([$this->path . 'segment_1.ts', $this->path . 'segment_2.ts'], $store->writes);
            $this->assertSame(['segment_3.ts'], $this->left());
        });
    }

    #[RunInSeparateProcess]
    public function testAbandonDropsTheQueueAndWaitsForTheUploadInFlight(): void
    {
        run(function (): void {
            $store = new Store($this->root, delay: 0.05);
            $uploader = new Uploader($store, $this->path);
            $uploader->start();

            $segments = $this->segments(5);
            foreach ($segments as $written) {
                $uploader->take($written);
            }
            // Let the first write start.
            Coroutine::sleep(0.01);

            $start = \microtime(true);
            $uploader->abandon();
            $elapsed = \microtime(true) - $start;

            $this->assertLessThan(0.2, $elapsed, 'abandon() uploaded what was queued');
            $this->assertSame([$this->path . 'segment_1.ts'], $store->writes);
            $this->assertSame(['segment_2.ts', 'segment_3.ts', 'segment_4.ts', 'segment_5.ts'], $this->left());

            // Harmless when repeated, and before start().
            $uploader->abandon();
            (new Uploader($store, $this->path))->abandon();
            $this->assertSame([$this->path . 'segment_1.ts'], $store->writes);
        });
    }

    /**
     * Stages segment files in the workspace: segment_N.ts holds 100 * N bytes.
     *
     * @return list<Written>
     */
    private function segments(int $count): array
    {
        $written = [];

        for ($number = 1; $number <= $count; $number++) {
            $file = 'segment_' . $number . '.ts';
            $path = $this->workspace . '/' . $file;
            $body = \str_repeat((string) ($number % 10), 100 * $number);
            \file_put_contents($path, $body);
            $written[] = new Written($file, $path, \strlen($body));
        }

        return $written;
    }

    /**
     * Segment files still in the workspace, sorted by name.
     *
     * @return list<string>
     */
    private function left(): array
    {
        $names = [];

        foreach (\scandir($this->workspace) ?: [] as $name) {
            if ($name[0] !== '.') {
                $names[] = $name;
            }
        }
        \sort($names, SORT_NATURAL);

        return $names;
    }

    private function remove(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? \rmdir($file->getPathname()) : \unlink($file->getPathname());
        }
        \rmdir($directory);
    }
}
