<?php

namespace Tests\Compat\Compression;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Compression\Algorithms\Brotli;
use Utopia\Compression\Algorithms\Deflate;
use Utopia\Compression\Algorithms\GZIP;
use Utopia\Compression\Algorithms\LZ4;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;

/**
 * Maps tests/compat/compression/spec.json operations onto utopia-php/compression. Glue only.
 *
 * An algorithm argument is {class, level?, set_level?, mode?}: `level` goes to
 * the constructor (LZ4, Zstd), `set_level` to setLevel(), `mode` to
 * use{Generic,Text,Font}Mode(). Data is `data` (a string or $bytes) or `file`
 * (a path from the checkout root). Results of compress and decompress are
 * {value, warning}: the returned string (or its {length, sha256} with
 * `digest`) and the first warning the extension emitted.
 */
final class Adapter implements Base, Benchmarks
{
    /** The benchmarks' payload: the first 64 KiB of a text resource of the PHP tests. */
    private const string PAYLOAD = 'packages/compression/tests/resources/disk-a/lorem.txt';

    public function benchmarks(): array
    {
        $payload = fn (): string => substr((string) file_get_contents(self::PAYLOAD), 0, 65536);

        return [
            'gzip' => ['GZIP: compress a 64 KiB text payload (gzencode, zlib level 6)', function (Session $s) use ($payload) {
                $data = $payload();
                $gzip = new GZIP();

                return fn () => $gzip->compress($data);
            }],
            'deflate_decompress' => ['Deflate: decompress a 64 KiB text payload (gzinflate)', function (Session $s) use ($payload) {
                $deflate = new Deflate();
                $data = $deflate->compress($payload());

                return fn () => $deflate->decompress($data);
            }],
            'zstd' => ['Zstd: compress a 64 KiB text payload at level 3', function (Session $s) use ($payload) {
                $data = $payload();
                $zstd = new Zstd();

                return fn () => $zstd->compress($data);
            }],
            'zstd_decompress' => ['Zstd: decompress a 64 KiB text payload', function (Session $s) use ($payload) {
                $zstd = new Zstd();
                $data = $zstd->compress($payload());

                return fn () => $zstd->decompress($data);
            }],
            'negotiate' => ['Choose the algorithm for a browser Accept-Encoding header', fn (Session $s) => fn () => Compression::fromAcceptEncoding('gzip, deflate, br, zstd;q=0.9')],
        ];
    }

    public function operations(): array
    {
        return [
            'compress' => fn (array $a, Session $s) => self::outcome(fn () => self::algorithm($a)->compress(self::data($a)), $a),
            'decompress' => fn (array $a, Session $s) => self::outcome(fn () => self::algorithm($a)->decompress(self::data($a)), $a),
            'roundtrip' => function (array $a, Session $s) {
                $algorithm = self::algorithm($a);
                $data = self::data($a);
                $compressed = null;
                $result = self::outcome(function () use ($algorithm, $data, &$compressed) {
                    $compressed = $algorithm->compress($data);

                    return $algorithm->decompress($compressed);
                }, $a);

                return $result + ['smaller' => \strlen((string) $compressed) < \strlen($data)];
            },
            'corrupt' => function (array $a, Session $s) {
                $algorithm = self::algorithm($a);
                $compressed = $algorithm->compress(self::data($a));
                $from = $a['from'] ?? 0;
                if (\strlen($compressed) > $from) {
                    $at = $from + $a['at'] % (\strlen($compressed) - $from);
                    $compressed[$at] = \chr(\ord($compressed[$at]) ^ $a['xor']);
                }

                return self::outcome(fn () => $algorithm->decompress($compressed), $a);
            },
            'describe' => fn (array $a, Session $s) => self::describe(self::algorithm($a)),
            'is_supported' => fn (array $a, Session $s) => self::class($a['class'])::isSupported(),
            'from_name' => fn (array $a, Session $s) => self::describe(Compression::fromName($a['name'])),
            'from_accept_encoding' => fn (array $a, Session $s) => self::describe(
                Compression::fromAcceptEncoding($a['accept'], ...(\array_key_exists('supported', $a) ? [$a['supported']] : []))
            ),
            'custom' => function (array $a, Session $s) {
                $custom = new class ($a['name']) extends Compression {
                    public function __construct(private readonly string $name)
                    {
                    }

                    public function getName(): string
                    {
                        return $this->name;
                    }

                    public function compress(string $data): string
                    {
                        return $data;
                    }

                    public function decompress(string $data): string
                    {
                        return $data;
                    }

                    public static function isSupported(): bool
                    {
                        return true;
                    }
                };

                return ['name' => $custom->getName(), 'encoding' => $custom->getContentEncoding()];
            },
        ];
    }

    /**
     * @return class-string<Compression>
     */
    private static function class(string $class): string
    {
        $class = 'Utopia\\Compression\\Algorithms\\' . $class;

        return is_subclass_of($class, Compression::class) ? $class : throw new Fault("unknown algorithm {$class}");
    }

    private static function algorithm(array $a): Compression
    {
        $spec = $a['algorithm'];
        $class = self::class($spec['class']);
        $algorithm = isset($spec['level']) ? new $class($spec['level']) : new $class();
        if (isset($spec['set_level'])) {
            if (!$algorithm instanceof Brotli && !$algorithm instanceof LZ4 && !$algorithm instanceof Zstd) {
                throw new Fault('only Brotli, LZ4 and Zstd have levels');
            }
            $algorithm->setLevel($spec['set_level']);
        }
        if (isset($spec['mode'])) {
            if (!$algorithm instanceof Brotli) {
                throw new Fault('only Brotli has modes');
            }
            match ($spec['mode']) {
                'generic' => $algorithm->useGenericMode(),
                'text' => $algorithm->useTextMode(),
                'font' => $algorithm->useFontMode(),
                default => throw new Fault("unknown mode {$spec['mode']}"),
            };
        }

        return $algorithm;
    }

    private static function data(array $a): string
    {
        if (isset($a['file'])) {
            $data = file_get_contents($a['file']);

            return $data === false ? throw new Fault("cannot read {$a['file']}") : $data;
        }

        return $a['data'];
    }

    /**
     * Runs $call, keeping the first warning it emits.
     *
     * @return array{value: mixed, warning: ?string}
     */
    private static function outcome(callable $call, array $a): array
    {
        $warning = null;
        set_error_handler(function (int $level, string $message) use (&$warning): bool {
            $warning ??= $message;

            return true;
        });
        try {
            $value = $call();
        } finally {
            restore_error_handler();
        }

        return [
            'value' => ($a['digest'] ?? false) ? ['length' => \strlen($value), 'sha256' => hash('sha256', $value)] : $value,
            'warning' => $warning,
        ];
    }

    private static function describe(?Compression $algorithm): ?array
    {
        if ($algorithm === null) {
            return null;
        }
        $description = [
            'class' => (new \ReflectionClass($algorithm))->getShortName(),
            'name' => $algorithm->getName(),
            'encoding' => $algorithm->getContentEncoding(),
            'supported' => $algorithm::isSupported(),
        ];
        if (method_exists($algorithm, 'getLevel')) {
            $description['level'] = $algorithm->getLevel();
        }

        return $description;
    }
}
