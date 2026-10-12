<?php

namespace Tests\Compat\Storage;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Client\Adapter as ClientAdapter;
use Utopia\Client\Decorator\Retry;
use Utopia\Client\Destinations;
use Utopia\Client\Exception\ConnectionException;
use Utopia\Client\Exception\DnsException;
use Utopia\Client\Exception\NetworkException;
use Utopia\Client\Exception\TimeoutException;
use Utopia\Client\Exception\TlsException;
use Utopia\Client\Redirect;
use Utopia\Client\Tls;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Psr7\Uri;
use Utopia\Storage\Acl;
use Utopia\Storage\Device;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\S3\RetryStrategy;
use Utopia\Storage\Device\Telemetry;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Exception\RemoteException;
use Utopia\Storage\Exception\StorageException;
use Utopia\Storage\FileInfo;
use Utopia\Storage\FileList;
use Utopia\Storage\UploadList;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileName;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\FileType;
use Utopia\Storage\Validator\Upload;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;
use Utopia\Validator\Validator;

/**
 * Maps tests/compat/storage/spec.json operations onto utopia-php/storage. Glue only.
 */
final class Adapter implements Base, Benchmarks
{
    public function benchmarks(): array
    {
        $local = static function (Session $s): Local {
            $root = "/tmp/compat-fs/{$s->ns}/bench";
            @mkdir($root, 0755, true);

            return new Local($root);
        };
        $payload = str_repeat('0123456789abcdef', 4096); // 64 KB

        return [
            'local_write' => ['Write a 64 KB file on the local disk (atomic rename, MD5 ETag)', function (Session $s) use ($local, $payload) {
                $device = $local($s);
                $path = $device->getPath('write.bin');

                return fn () => $device->write($path, new Stream($payload), 'application/octet-stream');
            }],
            'local_read' => ['Read a 64 KB local file to the end', function (Session $s) use ($local, $payload) {
                $device = $local($s);
                $path = $device->getPath('read.bin');
                file_put_contents($path, $payload);

                return fn () => (string) $device->read($path);
            }],
            'local_read_range' => ['Read a 4 KB range from the middle of a 64 KB local file', function (Session $s) use ($local, $payload) {
                $device = $local($s);
                $path = $device->getPath('range.bin');
                file_put_contents($path, $payload);

                return fn () => (string) $device->read($path, 30000, 4096);
            }],
            's3_write' => ['Write a 64 KB object to MinIO (SigV4-signed PUT)', function (Session $s) use ($payload) {
                $device = self::minio($s, []);
                $path = $device->getPath("{$s->ns}/bench.bin");

                return fn () => $device->write($path, new Stream($payload), 'application/octet-stream');
            }],
            'validate_file_name' => ['Validate an upload file name', function (Session $s) {
                $validator = new FileName();

                return fn () => $validator->isValid('kitten-photo_2024.final.jpg');
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'local.new' => fn (array $a, Session $s) => $s->handle(new Local($a['root'] ?? '')),
            's3.new' => fn (array $a, Session $s) => $s->handle(self::s3($a, $s)),
            'provider.new' => fn (array $a, Session $s) => $s->handle(self::provider($a, $s)),
            'telemetry.new' => function (array $a, Session $s) {
                $telemetry = new TestTelemetry();
                $device = new Telemetry($telemetry, self::device($a, $s));
                self::$telemetry[spl_object_id($device)] = $telemetry;

                return $s->handle($device);
            },
            'scripted.new' => fn (array $a, Session $s) => $s->handle(new ScriptedClient($a['responses'] ?? [])),
            'scripted.requests' => fn (array $a, Session $s) => array_map(self::request(...), self::scripted($a, $s)->requests),
            'metadata.new' => fn (array $a, Session $s) => $s->handle(new \ArrayObject(self::metadataIn($a['value'] ?? []))),
            'metadata.get' => fn (array $a, Session $s) => self::metadataOut(self::metadata($a, $s)->getArrayCopy()),

            'device.type' => fn (array $a, Session $s) => self::device($a, $s)->getType()->value,
            'device.root' => fn (array $a, Session $s) => self::device($a, $s)->getRoot(),
            'device.path' => fn (array $a, Session $s) => self::device($a, $s)->getPath($a['filename']),
            'device.absolute_path' => fn (array $a, Session $s) => (isset($a['device']) ? self::device($a, $s) : new Local())->getAbsolutePath($a['path']),
            'local.write_read' => function (array $a, Session $s) {
                $device = new Local("/tmp/compat-fs/{$s->ns}");
                $path = $device->getPath($a['path']);
                $etag = $device->write($path, new Stream($a['data']), '');

                return [
                    'etag' => $etag,
                    'read' => $device->read($path, $a['offset'], $a['length'])->getContents(),
                    'size' => $device->getFileSize($path),
                    'hash' => $device->getFileHash($path),
                ];
            },
            's3.wire' => function (array $a, Session $s) {
                $client = new ScriptedClient([$a['response']]);
                $device = new S3('/root', 'key', 'secret', $a['host'], $a['region'], client: $client);
                try {
                    $result = match ($a['call']) {
                        'write' => $device->write($a['path'], new Stream($a['data']), $a['content_type']),
                        'read' => $device->read($a['path'], $a['offset'], $a['length'])->getContents(),
                        'delete' => $device->delete($a['path']),
                        'list' => self::fileList($device->listFiles($a['path'], 1000, $a['cursor'] ?? null)),
                        'info' => self::fileInfo($device->getFileInfo($a['path'])),
                        default => throw new Fault('unknown call'),
                    };
                } catch (StorageException $e) {
                    $result = ['class' => $e::class, 'message' => $e->getMessage(), 'code' => $e->getCode()];
                }

                return ['result' => $result, 'requests' => array_map(self::request(...), $client->requests)];
            },
            'device.prepare' => fn (array $a, Session $s) => self::run($a, function () use ($a, $s) {
                $metadata = self::metadata($a, $s);
                $array = $metadata->getArrayCopy();
                try {
                    self::device($a, $s)->prepare($a['path'], $a['content_type'] ?? '', $a['chunks'] ?? 1, $array);
                } finally {
                    $metadata->exchangeArray($array);
                }

                return;
            }),
            'device.upload' => fn (array $a, Session $s) => self::run($a, function () use ($a, $s) {
                $metadata = isset($a['metadata']) ? self::metadata($a, $s) : new \ArrayObject();
                $array = $metadata->getArrayCopy();
                try {
                    return self::device($a, $s)->upload(self::body($a), $a['path'], $a['content_type'] ?? '', $a['chunk'] ?? 1, $a['chunks'] ?? 1, $array);
                } finally {
                    $metadata->exchangeArray($array);
                }
            }),
            'device.finalize' => fn (array $a, Session $s) => self::run($a, function () use ($a, $s) {
                $metadata = self::metadata($a, $s);
                $array = $metadata->getArrayCopy();
                try {
                    return self::device($a, $s)->finalize($a['path'], $a['chunks'] ?? 1, $array);
                } finally {
                    $metadata->exchangeArray($array);
                }
            }),
            'device.abort' => fn (array $a, Session $s) => self::run($a, fn () => self::device($a, $s)->abort($a['path'], $a['upload_id'] ?? '')),
            'device.read' => fn (array $a, Session $s) => self::run($a, fn () => self::read($a, $s)->getContents()),
            'device.read_digest' => fn (array $a, Session $s) => self::run($a, function () use ($a, $s) {
                $data = self::read($a, $s)->getContents();

                return ['size' => \strlen($data), 'md5' => md5($data)];
            }),
            'device.read_stream' => fn (array $a, Session $s) => $s->handle(self::read($a, $s)),
            'stream.read' => fn (array $a, Session $s) => self::stream($a, $s)->read($a['length']),
            'device.write' => fn (array $a, Session $s) => self::run($a, fn () => self::device($a, $s)->write($a['path'], self::body($a), $a['content_type'] ?? '')),
            'device.create' => fn (array $a, Session $s) => self::run($a, fn () => self::device($a, $s)->create($a['path'], self::body($a), $a['content_type'] ?? '')),
            'device.replace' => fn (array $a, Session $s) => self::run($a, fn () => self::device($a, $s)->replace($a['path'], self::body($a), $a['etag'], $a['content_type'] ?? '')),
            'device.copy' => fn (array $a, Session $s) => self::run($a, fn () => self::device($a, $s)->copy(
                $a['source'],
                $a['target'],
                isset($a['to']) ? self::device(['device' => $a['to']], $s) : null,
                $a['chunk_size'] ?? Device::COPY_CHUNK_SIZE,
            )),
            'device.move' => fn (array $a, Session $s) => self::run($a, fn () => self::device($a, $s)->move($a['source'], $a['target'])),
            'device.delete' => fn (array $a, Session $s) => self::run($a, fn () => self::device($a, $s)->delete($a['path'], $a['recursive'] ?? false)),
            'device.delete_path' => fn (array $a, Session $s) => self::run($a, fn () => self::device($a, $s)->deletePath($a['path'])),
            'device.exists' => fn (array $a, Session $s) => self::run($a, fn () => self::device($a, $s)->exists($a['path'])),
            'device.list_files' => fn (array $a, Session $s) => self::run($a, fn () => self::fileList(self::device($a, $s)->listFiles($a['prefix'] ?? '', $a['max'] ?? 1000, $a['cursor'] ?? null))),
            'device.file_info' => fn (array $a, Session $s) => self::run($a, fn () => self::fileInfo(self::device($a, $s)->getFileInfo($a['path']))),
            'device.file_size' => fn (array $a, Session $s) => self::run($a, fn () => self::device($a, $s)->getFileSize($a['path'])),
            'device.file_mime_type' => fn (array $a, Session $s) => self::run($a, fn () => self::device($a, $s)->getFileMimeType($a['path'])),
            'device.file_hash' => fn (array $a, Session $s) => self::run($a, fn () => self::device($a, $s)->getFileHash($a['path'])),

            'local.create_directory' => fn (array $a, Session $s) => self::local($a, $s)->createDirectory($a['path']),
            'local.directory_size' => fn (array $a, Session $s) => self::local($a, $s)->getDirectorySize($a['path']),
            'local.partition_free_space' => fn (array $a, Session $s) => self::local($a, $s)->getPartitionFreeSpace(),
            'local.partition_total_space' => fn (array $a, Session $s) => self::local($a, $s)->getPartitionTotalSpace(),
            's3.list_uploads' => fn (array $a, Session $s) => self::run($a, function () use ($a, $s) {
                $device = self::device($a, $s);
                if (! $device instanceof S3) {
                    throw new Fault('not an S3 device');
                }

                return self::uploadList($device->listUploads($a['prefix'] ?? '', $a['max'] ?? 1000, $a['cursor'] ?? null));
            }),
            'telemetry.list_uploads' => fn (array $a, Session $s) => self::run($a, fn () => self::uploadList(self::telemetry($a, $s)->listUploads($a['prefix'] ?? '', $a['max'] ?? 1000, $a['cursor'] ?? null))),
            'telemetry.device_is' => fn (array $a, Session $s) => self::telemetry($a, $s)->getDevice() === $s->get($a['of']),
            'telemetry.recorded' => function (array $a, Session $s) {
                $telemetry = self::$telemetry[spl_object_id(self::telemetry($a, $s))];
                $histograms = [];
                foreach ($telemetry->histograms as $name => $histogram) {
                    $histograms[$name] = \count($histogram->values ?? []);
                }

                return (object) $histograms;
            },

            'retry.delay' => fn (array $a, Session $s) => self::retryStrategy($a)->delay(
                self::psrRequest($a['request']),
                $a['attempt'] ?? 1,
                isset($a['response']) ? self::psrResponse($a['response']) : null,
                isset($a['error']) ? self::clientError($a['error'], self::psrRequest($a['request'])) : null,
            ),

            'file_ext.validate' => fn (array $a, Session $s) => self::validate(new FileExt($a['allowed']), $a['value'] ?? null),
            'file_name.validate' => fn (array $a, Session $s) => self::validate(new FileName(), $a['value'] ?? null),
            'file_size.validate' => fn (array $a, Session $s) => self::validate(new FileSize($a['max']), $a['value'] ?? null),
            'file_type.validate' => fn (array $a, Session $s) => self::validate(new FileType($a['allowed']), $a['value'] ?? null),
            'upload.validate' => fn (array $a, Session $s) => self::validate(new Upload(), $a['value'] ?? null),

            // Fixtures: direct file access, as the PHP tests use.
            'fs.put' => fn (array $a, Session $s) => self::put($a['path'], str_repeat($a['data'] ?? '', $a['repeat'] ?? 1)),
            'fs.get' => fn (array $a, Session $s) => is_file($a['path']) ? file_get_contents($a['path']) : false,
            'fs.exists' => fn (array $a, Session $s) => file_exists($a['path']),
            'fs.is_dir' => fn (array $a, Session $s) => is_dir($a['path']),
            'fs.mkdir' => fn (array $a, Session $s) => is_dir($a['path']) || mkdir($a['path'], 0755, true),
            'fs.perms' => fn (array $a, Session $s) => substr(sprintf('%o', fileperms($a['path'])), -4),
            'fs.md5' => fn (array $a, Session $s) => md5_file($a['path']),
            'fs.unlink' => fn (array $a, Session $s) => @unlink($a['path']),
        ];
    }

    /** @var array<int, TestTelemetry> */
    private static array $telemetry = [];

    /**
     * Runs an operation; with `catch`, a storage failure is returned with its
     * code, error code and previous exception instead of thrown.
     */
    private static function run(array $a, callable $operation): mixed
    {
        if (! ($a['catch'] ?? false)) {
            return $operation();
        }
        try {
            return ['ok' => $operation()];
        } catch (StorageException $e) {
            return [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'error_code' => $e instanceof RemoteException ? $e->errorCode : null,
                'previous' => $e->getPrevious() !== null,
            ];
        }
    }

    private static function put(string $path, string $data): bool
    {
        if (! is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0755, true);
        }

        return file_put_contents($path, $data) !== false;
    }

    private static function minio(Session $s, array $a): S3
    {
        $endpoint = $s->service('storage-minio');

        return new S3(
            $a['root'] ?? $s->ns,
            'minioadmin',
            'minioadmin',
            $endpoint . '/' . ($a['bucket_path'] ?? 'compat'),
            'us-east-1',
            ...[...self::acl($a), ...(isset($a['bucket']) ? ['bucket' => $a['bucket']] : [])],
        );
    }

    /**
     * @return array{acl?: ?Acl}
     */
    private static function acl(array $a): array
    {
        if (! \array_key_exists('acl', $a)) {
            return [];
        }

        return ['acl' => $a['acl'] === null ? null : Acl::from($a['acl'])];
    }

    private static function client(array $a, Session $s): ?ClientAdapter
    {
        if (! isset($a['client'])) {
            return null;
        }
        $client = self::scripted(['client' => $a['client']], $s);
        if (isset($a['retry_delay'])) {
            return new Retry($client, new RetryStrategy(delay: (float) $a['retry_delay']), static fn (float $seconds) => null);
        }

        return $client;
    }

    private static function s3(array $a, Session $s): S3
    {
        if (($a['minio'] ?? false) === true) {
            return self::minio($s, $a);
        }

        return new S3(
            $a['root'],
            $a['access_key'] ?? 'test-key',
            $a['secret_key'] ?? 'test-secret',
            $a['host'],
            $a['region'] ?? 'us-east-1',
            ...[
                ...self::acl($a),
                ...(self::client($a, $s) !== null ? ['client' => self::client($a, $s)] : []),
                ...(isset($a['bucket']) ? ['bucket' => $a['bucket']] : []),
            ],
        );
    }

    private static function provider(array $a, Session $s): S3
    {
        $class = match ($a['provider']) {
            'aws' => AWS::class,
            'backblaze' => Backblaze::class,
            'do_spaces' => DOSpaces::class,
            'linode' => Linode::class,
            'wasabi' => Wasabi::class,
            default => throw new Fault('unknown provider'),
        };

        return new $class(
            $a['root'],
            $a['access_key'] ?? 'test-key',
            $a['secret_key'] ?? 'test-secret',
            $a['bucket'],
            ...[
                ...(isset($a['region']) ? ['region' => $a['region']] : []),
                ...self::acl($a),
                ...(self::client($a, $s) !== null ? ['client' => self::client($a, $s)] : []),
            ],
        );
    }

    private static function device(array $a, Session $s): Device
    {
        $device = $s->get($a['device']);

        return $device instanceof Device ? $device : throw new Fault('not a device');
    }

    private static function local(array $a, Session $s): Local
    {
        $device = self::device($a, $s);

        return $device instanceof Local ? $device : throw new Fault('not a local device');
    }

    private static function telemetry(array $a, Session $s): Telemetry
    {
        $device = self::device($a, $s);

        return $device instanceof Telemetry ? $device : throw new Fault('not a telemetry device');
    }

    private static function scripted(array $a, Session $s): ScriptedClient
    {
        $client = $s->get($a['client']);

        return $client instanceof ScriptedClient ? $client : throw new Fault('not a scripted client');
    }

    private static function stream(array $a, Session $s): StreamInterface
    {
        $stream = $s->get($a['stream']);

        return $stream instanceof StreamInterface ? $stream : throw new Fault('not a stream');
    }

    private static function metadata(array $a, Session $s): \ArrayObject
    {
        $metadata = $s->get($a['metadata']);

        return $metadata instanceof \ArrayObject ? $metadata : throw new Fault('not metadata');
    }

    private static function read(array $a, Session $s): StreamInterface
    {
        return self::device($a, $s)->read($a['path'], $a['offset'] ?? 0, $a['length'] ?? null, $a['etag'] ?? null);
    }

    /**
     * The body of a write: `data` in memory, the fixture `file`, a stream
     * that `failing` after one chunk, one that is not seekable
     * (`unseekable`), or `data` read `consumed` bytes into.
     */
    private static function body(array $a): StreamInterface
    {
        if (isset($a['file'], $a['length'])) {
            $slice = file_get_contents($a['file'], false, null, $a['offset'] ?? 0, $a['length']);

            return $slice === false ? throw new Fault('cannot read ' . $a['file']) : new Stream($slice);
        }
        if (isset($a['file'])) {
            $handle = fopen($a['file'], 'rb');

            return $handle === false ? throw new Fault('cannot open ' . $a['file']) : Stream::fromResource($handle);
        }
        if (($a['failing'] ?? false) === true) {
            return new FailingStream();
        }
        if (isset($a['unseekable'])) {
            return new UnseekableStream($a['unseekable']);
        }
        $stream = new Stream($a['data'] ?? '');
        if (isset($a['consumed'])) {
            $stream->read($a['consumed']);
        }

        return $stream;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function metadataIn(array|\stdClass $value): array
    {
        $value = (array) $value;
        $metadata = [];
        foreach (['parts', 'chunks', 'content_type', 'uploadId', 'whole'] as $key) {
            if (! \array_key_exists($key, $value)) {
                continue;
            }
            if ($key === 'parts') {
                $parts = [];
                foreach ($value['parts'] as [$chunk, $part]) {
                    $parts[$chunk] = $part;
                }
                $metadata['parts'] = $parts;

                continue;
            }
            $metadata[$key] = $value[$key];
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function metadataOut(array $metadata): \stdClass
    {
        $out = [];
        foreach (['parts', 'chunks', 'content_type', 'uploadId', 'whole'] as $key) {
            if (! \array_key_exists($key, $metadata)) {
                continue;
            }
            if ($key === 'parts') {
                $out['parts'] = array_map(null, array_keys($metadata['parts']), array_values($metadata['parts']));

                continue;
            }
            $out[$key] = $metadata[$key];
        }

        return (object) $out;
    }

    private static function date(?\DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d\TH:i:s.uP');
    }

    private static function fileInfo(FileInfo $info): array
    {
        return ['path' => $info->path, 'size' => $info->size, 'modified' => self::date($info->modifiedAt), 'etag' => $info->etag];
    }

    private static function fileList(FileList $list): array
    {
        return ['files' => array_map(self::fileInfo(...), $list->files), 'cursor' => $list->cursor];
    }

    private static function uploadList(UploadList $list): array
    {
        $uploads = [];
        foreach ($list->uploads as $upload) {
            $uploads[] = ['path' => $upload->path, 'upload_id' => $upload->uploadId, 'initiated' => self::date($upload->initiatedAt)];
        }

        return ['uploads' => $uploads, 'cursor' => $list->cursor];
    }

    private static function validate(Validator $validator, mixed $value): array
    {
        return [
            'valid' => $validator->isValid($value),
            'description' => $validator->getDescription(),
            'type' => $validator->getType(),
            'array' => $validator->isArray(),
        ];
    }

    private static function retryStrategy(array $a): RetryStrategy
    {
        $random = $a['random'] ?? null;

        return new RetryStrategy(
            $a['retries'] ?? 3,
            (float) ($a['delay'] ?? 0.5),
            (float) ($a['max_delay'] ?? 20.0),
            $random === null ? null : static fn (): float => (float) $random,
        );
    }

    private static function psrRequest(array $r): RequestInterface
    {
        $request = new Request($r['method'], Uri::parse($r['url']));
        foreach ($r['headers'] ?? [] as [$name, $value]) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    public static function psrResponse(array $r): ResponseInterface
    {
        $response = new Response($r['status'], body: new Stream($r['body'] ?? ''));
        foreach ($r['headers'] ?? [] as [$name, $value]) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    public static function clientError(array $e, RequestInterface $request): ClientExceptionInterface
    {
        $message = $e['message'] ?? '';
        $code = $e['code'] ?? 0;

        return match ($e['error']) {
            'dns' => new DnsException($request, $message, $code),
            'connection' => new ConnectionException($request, $message, $code),
            'timeout' => new TimeoutException($request, $message, $code),
            'tls' => new TlsException($request, $message, $code),
            'network' => new NetworkException($request, $message, $code),
            default => throw new Fault('unknown client error ' . $e['error']),
        };
    }

    /**
     * A request as the scripted client saw it: headers by name, in name order.
     */
    private static function request(RequestInterface $request): array
    {
        $headers = [];
        foreach (array_keys($request->getHeaders()) as $name) {
            $headers[strtolower($name)] = $request->getHeaderLine($name);
        }
        ksort($headers, SORT_STRING);

        return [
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'headers' => $headers,
            'body' => (string) $request->getBody(),
        ];
    }
}

/**
 * Replays scripted responses and records every request.
 */
final class ScriptedClient implements ClientAdapter
{
    /** @var array<RequestInterface> */
    public array $requests = [];

    /**
     * @param  array<array<string, mixed>>  $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->responses);
        if ($next === null) {
            throw new NetworkException($request, 'No scripted response left');
        }
        if (isset($next['error'])) {
            throw Adapter::clientError($next, $request);
        }

        return Adapter::psrResponse($next);
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $response = $this->sendRequest($request);
        $sink((string) $response->getBody());

        return $response;
    }

    public function withDestinations(Destinations $destinations): static
    {
        return $this;
    }

    public function withTimeout(float $seconds): static
    {
        return $this;
    }

    public function withConnectTimeout(float $seconds): static
    {
        return $this;
    }

    public function withSslVerification(bool $enabled = true): static
    {
        return $this;
    }

    public function withCustomCA(string $path): static
    {
        return $this;
    }

    public function withCertificate(string $certPath, string $keyPath, ?string $passphrase = null): static
    {
        return $this;
    }

    public function withMinTlsVersion(Tls $version): static
    {
        return $this;
    }

    public function withConnectionReuse(bool $enabled = true): static
    {
        return $this;
    }

    public function withFollowRedirects(bool $enabled = true, int $maxHops = Redirect::MAX_HOPS): static
    {
        return $this;
    }
}

/**
 * Hands over one chunk, then fails: a full disk or a dropped connection.
 */
final class FailingStream implements StreamInterface
{
    private bool $sent = false;

    public function read(int $length): string
    {
        if ($this->sent) {
            throw new \DomainException('Stream failed');
        }
        $this->sent = true;

        return 'partial';
    }

    public function eof(): bool
    {
        return false;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    public function detach(): mixed
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function write(string $string): int
    {
        return 0;
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata(?string $key = null): mixed
    {
        return null;
    }
}

/**
 * Bytes read once: a stream that cannot be rewound.
 */
final class UnseekableStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private readonly string $data)
    {
    }

    public function read(int $length): string
    {
        $chunk = substr($this->data, $this->position, $length);
        $this->position += \strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->position >= \strlen($this->data);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function __toString(): string
    {
        return substr($this->data, $this->position);
    }

    public function close(): void
    {
    }

    public function detach(): mixed
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('Stream is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('Stream is not seekable');
    }

    public function write(string $string): int
    {
        return 0;
    }

    public function getContents(): string
    {
        return $this->read(PHP_INT_MAX);
    }

    public function getMetadata(?string $key = null): mixed
    {
        return null;
    }
}
