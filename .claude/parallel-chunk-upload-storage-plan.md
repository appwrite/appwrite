# Parallel Chunk Upload Support for utopia-php/storage

## Context

The Appwrite API now supports out-of-order chunked uploads (chunks can arrive in any sequence). The next step is **parallel uploads** — multiple chunks uploaded simultaneously via separate HTTP requests. The SDK guarantees the first chunk is sent before any parallel chunks, so the document creation race is handled at the API layer. However, the storage device layer has a race condition that must be fixed.

## Problem: `Local::joinChunks()` Race

When two requests upload the final missing chunks in parallel, both can observe `countChunks() == $chunks` and call `joinChunks()` simultaneously.

### Current behavior (loser throws)

```php
// Local::joinChunks()
$dest = \fopen($tmpAssemble, 'wb');
// ... stream all parts into $tmpAssemble ...

if (! \rename($tmpAssemble, $path)) {
    \unlink($tmpAssemble);
    throw new Exception('Failed to finalize assembled file '.$path);
}
```

The winner succeeds with `rename()`. The loser gets `false` from `rename()` (file already exists at `$path`) and throws a 500-error exception. The client that lost the race receives an error even though the file is fully assembled.

### Required behavior

If `$path` already exists, another request already assembled the file. The loser should **silently succeed** — the file is complete, nothing more to do.

## Proposed Changes

### 1. `Local::joinChunks()` — Handle assembly race

Before opening `$tmpAssemble`, check if the final file already exists. If it does, skip assembly entirely.

```php
private function joinChunks(string $path, int $chunks): void
{
    // Race winner already assembled the file
    if (\file_exists($path)) {
        return;
    }

    $tmp = \dirname($path).DIRECTORY_SEPARATOR.'tmp_'.asename($path);
    $tmpAssemble = \dirname($path).DIRECTORY_SEPARATOR.'tmp_assemble_'.asename($path);

    // ... rest of assembly logic ...

    if (! \rename($tmpAssemble, $path)) {
        // Another request may have won the race between fclose and rename
        if (\file_exists($path)) {
            \unlink($tmpAssemble);
            return;
        }
        \unlink($tmpAssemble);
        throw new Exception('Failed to finalize assembled file '.$path);
    }

    // ... cleanup ...
}
```

### 2. `Local::countChunks()` — Reliability under concurrent writes

`countChunks()` uses `glob()` on the temp directory. Under heavy parallel load, `glob()` might miss files or return inconsistent counts. The current implementation is already fairly robust (it validates `.part.\d+` suffix), but we should document that the return value is a best-effort snapshot.

No code change needed here unless tests reveal issues.

### 3. Tests — Concurrent chunk uploads

Add a test that simulates two parallel requests completing a multi-chunk upload:

```php
public function testParallelChunkUpload(): void
{
    $storage = $this->makeJoinTestStorage();
    $dest = $storage->getRoot().DIRECTORY_SEPARATOR.'parallel.dat';

    // Upload chunk 1 (creates temp directory)
    $storage->uploadData('AAAA', $dest, 'application/octet-stream', 1, 2);

    // Simulate two parallel requests uploading the last chunk
    // In a real test, use pcntl_fork() or pthreads for true concurrency
    // For the test suite, sequential calls are sufficient if we verify
    // the second call doesn't throw after the first completed assembly
    $storage->uploadData('BBBB', $dest, 'application/octet-stream', 2, 2);

    // Verify file exists and is correct
    $this->assertTrue(\file_exists($dest));
    $this->assertSame('AAAABBBB', \file_get_contents($dest));

    // Verify second assembly attempt doesn't throw
    // (This simulates the race where another request already assembled)
    try {
        $storage->uploadData('BBBB', $dest, 'application/octet-stream', 2, 2);
    } catch (\Exception $e) {
        $this->fail('Duplicate assembly should not throw: '.$e->getMessage());
    }

    $storage->delete($storage->getRoot(), true);
}
```

A more realistic concurrent test using `pcntl_fork()`:

```php
public function testParallelChunkUploadWithFork(): void
{
    if (!\function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl extension required for fork-based concurrency test');
    }

    $storage = $this->makeJoinTestStorage();
    $dest = $storage->getRoot().DIRECTORY_SEPARATOR.'parallel-fork.dat';

    // Pre-upload chunk 1
    $storage->uploadData('AAAA', $dest, 'application/octet-stream', 1, 2);

    $pid = pcntl_fork();
    if ($pid === -1) {
        $this->fail('Failed to fork');
    } elseif ($pid === 0) {
        // Child process: upload chunk 2
        try {
            $storage->uploadData('BBBB', $dest, 'application/octet-stream', 2, 2);
            exit(0);
        } catch (\Exception $e) {
            exit(1);
        }
    }

    // Parent process: also upload chunk 2 (race condition)
    $parentSuccess = true;
    try {
        $storage->uploadData('BBBB', $dest, 'application/octet-stream', 2, 2);
    } catch (\Exception $e) {
        $parentSuccess = false;
    }

    pcntl_waitpid($pid, $status);
    $childSuccess = pcntl_wexitstatus($status) === 0;

    // At least one should succeed
    $this->assertTrue($parentSuccess || $childSuccess, 'At least one parallel upload should succeed');

    // File should be correctly assembled
    $this->assertTrue(\file_exists($dest));
    $this->assertSame('AAAABBBB', \file_get_contents($dest));

    $storage->delete($storage->getRoot(), true);
}
```

## S3 Device

S3 already handles out-of-order multipart uploads natively. The `completeMultipartUpload` call with `ksort()` sorts parts by number regardless of upload order. However, parallel `completeMultipartUpload` calls for the same `uploadId` would still be problematic.

This is an **API-layer concern** — the Appwrite API should ensure only one request calls `completeMultipartUpload` per upload. The S3 device itself does not need changes.

## Files to Change

| File | Change |
|------|--------|
| `src/Storage/Device/Local.php` | Add `file_exists($path)` guard at start of `joinChunks()` and in `rename()` failure handler |
| `tests/Storage/Device/LocalTest.php` | Add `testParallelChunkUpload` and `testParallelChunkUploadWithFork` |

## Backwards Compatibility

Fully backwards compatible. The change only affects the error path when `rename()` fails due to an existing file. Previously it threw; now it returns silently. No public API signatures change.

## Related PRs

- Appwrite server PR: https://github.com/appwrite/appwrite/pull/12138 (out-of-order upload support)
- This storage PR is a prerequisite for the follow-up Appwrite PR that enables parallel chunk uploads at the API level.
