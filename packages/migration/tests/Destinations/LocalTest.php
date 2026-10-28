<?php

namespace Utopia\Migration\Tests\Destinations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Migration\Cache;
use Utopia\Migration\Destinations\Local;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Functions\Deployment;
use Utopia\Migration\Resources\Functions\Func;
use Utopia\Migration\Resources\Storage\Bucket;
use Utopia\Migration\Resources\Storage\File;

class LocalTest extends TestCase
{
    private string $exportDir;

    private string $outsideFile;

    private TestableLocal $destination;

    protected function setUp(): void
    {
        $this->exportDir = \sys_get_temp_dir() . '/migration-local-' . \uniqid('', true);
        $this->outsideFile = \dirname($this->exportDir) . '/migration-local-outside-' . \uniqid('', true) . '.txt';
        \file_put_contents($this->outsideFile, 'original');

        $this->destination = new TestableLocal($this->exportDir);
        $cache = new Cache();
        $this->destination->registerCache($cache);
    }

    protected function tearDown(): void
    {
        if (\is_file($this->outsideFile)) {
            \unlink($this->outsideFile);
        }

        $this->recursiveDelete($this->exportDir);
    }

    public function testNestedAndDottedNamesLandUnderFiles(): void
    {
        $nested = $this->file('nested', 'images/photo.png', 'nested-bytes');
        $dotted = $this->file('dotted', 'my.file.tar.gz', 'dotted-bytes');

        $this->destination->importResources([$nested, $dotted], static function (): void {
        });

        $this->assertSame(Resource::STATUS_SUCCESS, $nested->getStatus());
        $this->assertSame(Resource::STATUS_SUCCESS, $dotted->getStatus());
        $this->assertSame('nested-bytes', \file_get_contents($this->exportDir . '/files/images/photo.png'));
        $this->assertSame('dotted-bytes', \file_get_contents($this->exportDir . '/files/my.file.tar.gz'));
        $this->assertSame('original', \file_get_contents($this->outsideFile));
    }

    #[DataProvider('traversalNames')]
    public function testTraversalNamesLeaveOutsideFileUntouched(string $name): void
    {
        $file = $this->file('bad', $name, 'owned');

        $this->destination->importResources([$file], static function (): void {
        });

        $this->assertSame(Resource::STATUS_ERROR, $file->getStatus());
        $this->assertSame('original', \file_get_contents($this->outsideFile));
        $this->assertCount(1, $this->destination->getErrors());
        $this->assertFalse($this->pathExistsOutsideFiles($name));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function traversalNames(): array
    {
        return [
            'parent' => ['../migration-escape.txt'],
            'grandparent' => ['../../migration-escape.txt'],
            'nestedParent' => ['foo/../../migration-escape.txt'],
            'absolute' => ['/tmp/migration-escape.txt'],
            'backslash' => ['foo\\bar.txt'],
        ];
    }

    public function testSymlinkDirectoryDoesNotRedirectWrite(): void
    {
        $link = $this->exportDir . '/files/link';
        $this->assertTrue(\symlink(\dirname($this->outsideFile), $link));

        $file = $this->file('via-link', 'link/' . \basename($this->outsideFile), 'owned');

        $this->destination->importResources([$file], static function (): void {
        });

        $this->assertSame(Resource::STATUS_ERROR, $file->getStatus());
        $this->assertSame('original', \file_get_contents($this->outsideFile));
    }

    public function testSymlinkFileDoesNotRedirectWrite(): void
    {
        $link = $this->exportDir . '/files/linked.txt';
        $this->assertTrue(\symlink($this->outsideFile, $link));

        $file = $this->file('via-file-link', 'linked.txt', 'owned');

        $this->destination->importResources([$file], static function (): void {
        });

        $this->assertSame(Resource::STATUS_ERROR, $file->getStatus());
        $this->assertSame('original', \file_get_contents($this->outsideFile));
    }

    public function testInDirectoryFileIsReplacedThenAppended(): void
    {
        $path = $this->exportDir . '/files/chunked.bin';
        \file_put_contents($path, 'stale');

        $first = $this->file('chunked', 'chunked.bin', 'one', start: 0, end: 3);
        $second = $this->file('chunked', 'chunked.bin', 'two', start: 3, end: 6);

        $this->destination->importResources([$first, $second], static function (): void {
        });

        $this->assertSame(Resource::STATUS_SUCCESS, $first->getStatus());
        $this->assertSame(Resource::STATUS_SUCCESS, $second->getStatus());
        $this->assertSame('onetwo', \file_get_contents($path));
    }

    public function testBadFileInBatchDoesNotStopGoodFile(): void
    {
        $bad = $this->file('bad', '../escape.txt', 'owned');
        $good = $this->file('good', 'safe.txt', 'safe-bytes');

        $this->destination->importResources([$bad, $good], static function (): void {
        });

        $this->assertSame(Resource::STATUS_ERROR, $bad->getStatus());
        $this->assertSame(Resource::STATUS_SUCCESS, $good->getStatus());
        $this->assertSame('safe-bytes', \file_get_contents($this->exportDir . '/files/safe.txt'));
        $this->assertSame('original', \file_get_contents($this->outsideFile));
        $this->assertCount(1, $this->destination->getErrors());
    }

    public function testDeploymentIdTraversalDoesNotEscape(): void
    {
        $deployment = $this->deployment('../../x', 'payload');

        $this->destination->importResources([$deployment], static function (): void {
        });

        $this->assertSame(Resource::STATUS_ERROR, $deployment->getStatus());
        $this->assertSame('original', \file_get_contents($this->outsideFile));
        $this->assertFileDoesNotExist($this->exportDir . '/../../x.tar.gz');
        $this->assertCount(1, $this->destination->getErrors());
    }

    public function testDeploymentWritesUnderDeploymentsDirectory(): void
    {
        $deployment = $this->deployment('dep123', 'archive-bytes');

        $this->destination->importResources([$deployment], static function (): void {
        });

        $this->assertSame(Resource::STATUS_SUCCESS, $deployment->getStatus());
        $this->assertSame(
            'archive-bytes',
            \file_get_contents($this->exportDir . '/deployments/dep123.tar.gz')
        );
    }

    public function testFileWriteOntoDirectoryMarksError(): void
    {
        $path = $this->exportDir . '/files/blocked.txt';
        $this->assertTrue(\mkdir($path));

        // A later chunk does not unlink the existing path. A directory there
        // makes the append fail for every user, including root in CI.
        $file = $this->file('blocked', 'blocked.txt', 'owned', start: 1);
        $this->importIgnoringWriteWarning($this->destination, [$file]);

        $this->assertSame(Resource::STATUS_ERROR, $file->getStatus());
        $this->assertSame('Unable to write export file', $file->getMessage());
        $this->assertDirectoryExists($path);
        $this->assertSame(MigrationException::CODE_INTERNAL, $this->singleError()->getCode());
    }

    public function testDeploymentWriteOntoDirectoryMarksError(): void
    {
        $path = $this->exportDir . '/deployments/dep123.tar.gz';
        $this->assertTrue(\mkdir($path));

        $deployment = $this->deployment('dep123', 'archive-bytes');
        $this->importIgnoringWriteWarning($this->destination, [$deployment]);

        $this->assertSame(Resource::STATUS_ERROR, $deployment->getStatus());
        $this->assertSame('Unable to write export file', $deployment->getMessage());
        $this->assertDirectoryExists($path);
        $this->assertSame(MigrationException::CODE_INTERNAL, $this->singleError()->getCode());
    }

    #[DataProvider('linkedExportRoots')]
    public function testSymlinkedExportRootOutsideDestinationIsRejected(string $root): void
    {
        $exportDir = \sys_get_temp_dir() . '/migration-local-linked-' . \uniqid('', true);
        $outside = $exportDir . '-outside';

        \mkdir($exportDir);
        \mkdir($outside);
        \file_put_contents($outside . '/marker.txt', 'original');

        try {
            foreach (['files', 'deployments'] as $directory) {
                if ($directory === $root) {
                    \symlink($outside, $exportDir . '/' . $directory);
                    continue;
                }

                \mkdir($exportDir . '/' . $directory);
            }

            $rejected = false;

            try {
                new TestableLocal($exportDir);
            } catch (\Exception $e) {
                $rejected = true;
                $this->assertSame('Unable to resolve export directories', $e->getMessage());
                $this->assertSame(MigrationException::CODE_INTERNAL, $e->getCode());
            }

            $this->assertTrue($rejected);
            $this->assertSame('original', \file_get_contents($outside . '/marker.txt'));
        } finally {
            $this->recursiveDelete($exportDir);
            $this->recursiveDelete($outside);
        }
    }

    public function testSymlinkedExportRootInsideDestinationIsAccepted(): void
    {
        $exportDir = \sys_get_temp_dir() . '/migration-local-inner-' . \uniqid('', true);
        $outside = $exportDir . '-outside.txt';
        \file_put_contents($outside, 'original');

        try {
            \mkdir($exportDir);
            \mkdir($exportDir . '/storage');
            \mkdir($exportDir . '/archives');
            \symlink($exportDir . '/storage', $exportDir . '/files');
            \symlink($exportDir . '/archives', $exportDir . '/deployments');

            $destination = new TestableLocal($exportDir);
            $cache = new Cache();
            $destination->registerCache($cache);

            $file = $this->file('nested', 'images/photo.png', 'nested-bytes');
            $deployment = $this->deployment('dep123', 'archive-bytes');

            $destination->importResources([$file], static function (): void {
            });
            $destination->importResources([$deployment], static function (): void {
            });

            $this->assertSame(Resource::STATUS_SUCCESS, $file->getStatus());
            $this->assertSame(Resource::STATUS_SUCCESS, $deployment->getStatus());
            $this->assertSame('nested-bytes', \file_get_contents($exportDir . '/storage/images/photo.png'));
            $this->assertSame('archive-bytes', \file_get_contents($exportDir . '/archives/dep123.tar.gz'));
            $this->assertSame('original', \file_get_contents($outside));
        } finally {
            $this->recursiveDelete($exportDir);
            if (\is_file($outside)) {
                \unlink($outside);
            }
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function linkedExportRoots(): array
    {
        return [
            'files' => ['files'],
            'deployments' => ['deployments'],
        ];
    }

    private function file(
        string $id,
        string $name,
        string $data,
        int $start = 0,
        int $end = 0,
    ): File {
        return new File(
            $id,
            new Bucket('bucket'),
            $name,
            data: $data,
            start: $start,
            end: $end === 0 ? \strlen($data) : $end,
        );
    }

    private function deployment(string $id, string $data): Deployment
    {
        return new Deployment(
            $id,
            new Func('fn', 'Function', 'node-18.0'),
            \strlen($data),
            'index.js',
            0,
            \strlen($data),
            $data,
        );
    }

    /**
     * @param array<Resource> $resources
     */
    private function importIgnoringWriteWarning(TestableLocal $destination, array $resources): void
    {
        \set_error_handler(static function (int $severity, string $message): bool {
            return $severity === \E_WARNING && \str_contains($message, 'file_put_contents');
        });

        try {
            $destination->importResources($resources, static function (): void {
            });
        } finally {
            \restore_error_handler();
        }
    }

    private function singleError(): MigrationException
    {
        $errors = $this->destination->getErrors();
        $this->assertCount(1, $errors);
        $error = $errors[0];

        if (! $error instanceof MigrationException) {
            $this->fail('Expected a migration exception');
        }

        return $error;
    }

    private function pathExistsOutsideFiles(string $name): bool
    {
        $candidate = $this->exportDir . '/files/' . $name;

        return \file_exists($candidate);
    }

    private function recursiveDelete(string $path): void
    {
        if (! \file_exists($path) && ! \is_link($path)) {
            return;
        }

        if (\is_link($path) || \is_file($path)) {
            \unlink($path);

            return;
        }

        $entries = \scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->recursiveDelete($path . '/' . $entry);
        }

        \rmdir($path);
    }
}

class TestableLocal extends Local
{
    /**
     * @param array<Resource> $resources
     */
    public function importResources(array $resources, callable $callback): void
    {
        $this->import($resources, $callback);
    }
}
