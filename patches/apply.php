<?php
/**
 * Patches utopia-php/storage S3 device to stream large file uploads
 * instead of loading them entirely into memory (fixes memory exhaustion
 * for files > ~500MB).
 *
 * Applied to: vendor/utopia-php/storage/src/Storage/Device/S3.php
 * Fixes all S3-compatible providers (AWS, Backblaze, DOSpaces, etc.)
 * since they inherit uploadChunk from the base S3 class.
 */

$file = $argv[1] ?? __DIR__ . '/../vendor/utopia-php/storage/src/Storage/Device/S3.php';
$code = file_get_contents($file);
if ($code === false) {
    fwrite(STDERR, "Cannot read $file\n");
    exit(1);
}

$original = $code;

// === Fix 1: uploadChunk() — stream single-chunk uploads via cURL ===
$code = str_replace(
    <<<'PHP'
    public function uploadChunk(string $source, string $path, int $chunk = 1, int $chunks = 1, array &$metadata = []): int
    {
        $data = \file_get_contents($source);
        if ($data === false) {
            throw new Exception('Can\'t read file '.$source);
        }

        return $this->uploadChunkData($data, $path, $metadata['content_type'] ?? (\mime_content_type($source) ?: ''), $chunk, $chunks, $metadata);
    }
PHP,
    <<<'PHP'
    public function uploadChunk(string $source, string $path, int $chunk = 1, int $chunks = 1, array &$metadata = []): int
    {
        if ($chunk == 1 && $chunks == 1) {
            $contentType = $metadata['content_type'] ?? (\mime_content_type($source) ?: '');
            $uri = $path !== '' ? '/'.\str_replace(['%2F', '%3F'], ['/', '?'], \rawurlencode($path)) : '/';

            $this->headers['content-type'] = $contentType;
            $this->headers['content-md5'] = \base64_encode(md5_file($source, true));
            unset($this->amzHeaders['x-amz-content-sha256']);
            $this->amzHeaders['x-amz-acl'] = $this->acl;

            $this->call('s3:upload', self::METHOD_PUT, $uri, '', [], true, $source);

            $metadata['parts'][$chunk] = true;
            $metadata['chunks'] = 1;

            return 1;
        }

        $data = \file_get_contents($source);
        if ($data === false) {
            throw new Exception('Can\'t read file '.$source);
        }

        return $this->uploadChunkData($data, $path, $metadata['content_type'] ?? (\mime_content_type($source) ?: ''), $chunk, $chunks, $metadata);
    }
PHP,
    $code
);

// === Fix 2: call() — add optional $filePath parameter for streaming ===
$code = str_replace(
    'protected function call(string $operation, string $method, string $uri, string $data = \'\', array $parameters = [], bool $decode = true)',
    'protected function call(string $operation, string $method, string $uri, string $data = \'\', array $parameters = [], bool $decode = true, ?string $filePath = null)',
    $code
);

// === Fix 3: hash calculation — use hash_file for streaming ===
$code = str_replace(
    'if (! isset($this->amzHeaders[\'x-amz-content-sha256\'])) {' . "\n" .
    '            $this->amzHeaders[\'x-amz-content-sha256\'] = \\hash(\'sha256\', $data);' . "\n" .
    '        }',
    'if (! isset($this->amzHeaders[\'x-amz-content-sha256\'])) {' . "\n" .
    '            if ($filePath !== null) {' . "\n" .
    '                $this->amzHeaders[\'x-amz-content-sha256\'] = \\hash_file(\'sha256\', $filePath);' . "\n" .
    '            } else {' . "\n" .
    '                $this->amzHeaders[\'x-amz-content-sha256\'] = \\hash(\'sha256\', $data);' . "\n" .
    '            }' . "\n" .
    '        }',
    $code
);

// === Fix 4: switch statement — add file streaming for PUT ===
$code = str_replace(
    '        // Request types' . "\n" .
    '        switch ($method) {' . "\n" .
    '            case self::METHOD_PUT:' . "\n" .
    '            case self::METHOD_POST: // POST only used for CloudFront' . "\n" .
    '                \curl_setopt($curl, CURLOPT_POSTFIELDS, $data);' . "\n" .
    '                break;' . "\n" .
    '            case self::METHOD_HEAD:' . "\n" .
    '            case self::METHOD_DELETE:' . "\n" .
    '                \curl_setopt($curl, CURLOPT_NOBODY, true);' . "\n" .
    '                break;' . "\n" .
    '        }' . "\n" .
    '' . "\n" .
    '        $result = \curl_exec($curl);',
    '        // Request types' . "\n" .
    '        $fp = null;' . "\n" .
    '        switch ($method) {' . "\n" .
    '            case self::METHOD_PUT:' . "\n" .
    '            case self::METHOD_POST: // POST only used for CloudFront' . "\n" .
    '                if ($filePath !== null) {' . "\n" .
    '                    $fp = \fopen($filePath, \'rb\');' . "\n" .
    '                    if ($fp === false) {' . "\n" .
    '                        throw new \Exception(\'Failed to open file for streaming: \'.$filePath);' . "\n" .
    '                    }' . "\n" .
    '                    \curl_setopt($curl, CURLOPT_UPLOAD, true);' . "\n" .
    '                    \curl_setopt($curl, CURLOPT_INFILE, $fp);' . "\n" .
    '                    \curl_setopt($curl, CURLOPT_INFILESIZE, \filesize($filePath));' . "\n" .
    '                    break;' . "\n" .
    '                }' . "\n" .
    '                \curl_setopt($curl, CURLOPT_POSTFIELDS, $data);' . "\n" .
    '                break;' . "\n" .
    '            case self::METHOD_HEAD:' . "\n" .
    '            case self::METHOD_DELETE:' . "\n" .
    '                \curl_setopt($curl, CURLOPT_NOBODY, true);' . "\n" .
    '                break;' . "\n" .
    '        }' . "\n" .
    '' . "\n" .
    '        $result = \curl_exec($curl);',
    $code
);

// === Fix 5: rewind file handle on retry ===
$code = str_replace(
    '            \usleep(self::$retryDelay * 1000);' . "\n" .
    '            $attempt++;' . "\n" .
    '            $result = \curl_exec($curl);',
    '            \usleep(self::$retryDelay * 1000);' . "\n" .
    '            $attempt++;' . "\n" .
    '            if ($filePath !== null && isset($fp) && \is_resource($fp)) {' . "\n" .
    '                \rewind($fp);' . "\n" .
    '            }' . "\n" .
    '            $result = \curl_exec($curl);',
    $code
);

// === Fix 6: close file handle in finally ===
$code = str_replace(
    '            return $response;' . "\n" .
    '        } finally {' . "\n" .
    '            $this->storageOperationTelemetry->record(',
    '            return $response;' . "\n" .
    '        } finally {' . "\n" .
    '            if (isset($fp) && \is_resource($fp)) {' . "\n" .
    '                \fclose($fp);' . "\n" .
    '            }' . "\n" .
    '            $this->storageOperationTelemetry->record(',
    $code
);

if ($code === $original) {
    fwrite(STDERR, "Patch had no effect — source file may already be patched or format differs.\n");
    exit(1);
}

file_put_contents($file, $code);
echo "Patched $file successfully\n";
