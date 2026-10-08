<?php

declare(strict_types=1);

namespace Tests\E2E\Security;

/**
 * Checked-in allowlist for known probe results. CI fails on any finding whose
 * key is not listed, and on any listed finding that has no reason.
 */
final class Baseline
{
    public const string PATH = __DIR__ . '/baseline.json';

    public const string CACHE_PATH = '/storage/cache/security-baseline.json';

    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        private readonly array $document,
    ) {
    }

    public static function load(string $path = self::PATH): self
    {
        if (! \is_file($path)) {
            return new self([
                'version' => 1,
                'routes' => [],
                'findings' => [],
            ]);
        }

        $decoded = \json_decode((string) \file_get_contents($path), true);
        if (! \is_array($decoded)) {
            throw new \RuntimeException('Security baseline is not valid JSON: ' . $path);
        }

        return new self($decoded);
    }

    /**
     * @return list<string>
     */
    public function routes(): array
    {
        $listed = $this->document['routes'] ?? [];
        if (! \is_array($listed)) {
            return [];
        }

        return \array_values(\array_map(static fn (mixed $route): string => (string) $route, $listed));
    }

    /**
     * @return array<string, array{attack: string, method: string, path: string, probe: string, severity: string, reason: string}>
     */
    public function findings(): array
    {
        $entries = $this->document['findings'] ?? [];
        if (! \is_array($entries)) {
            return [];
        }

        $map = [];
        foreach ($entries as $entry) {
            if (! \is_array($entry)) {
                continue;
            }
            $finding = new Finding(
                attack: (string) ($entry['attack'] ?? ''),
                method: (string) ($entry['method'] ?? ''),
                path: (string) ($entry['path'] ?? ''),
                probe: (string) ($entry['probe'] ?? ''),
                detail: (string) ($entry['detail'] ?? ''),
                severity: (string) ($entry['severity'] ?? Finding::ERROR),
            );
            $map[$finding->key()] = [
                'attack' => $finding->attack,
                'method' => $finding->method,
                'path' => $finding->path,
                'probe' => $finding->probe,
                'severity' => $finding->severity,
                'reason' => (string) ($entry['reason'] ?? ''),
            ];
        }

        return $map;
    }

    /**
     * @param list<Finding> $findings
     * @return array{fresh: list<Finding>, known: list<Finding>, stale: list<string>, unreasoned: list<string>}
     */
    public function partition(array $findings): array
    {
        $known = $this->findings();
        $fresh = [];
        $matched = [];
        $unreasoned = [];

        foreach ($findings as $finding) {
            $key = $finding->key();
            if (! isset($known[$key])) {
                $fresh[] = $finding;
                continue;
            }
            $matched[$key] = true;
            if (\trim($known[$key]['reason']) === '') {
                $unreasoned[] = $key;
            }
        }

        $stale = [];
        foreach ($known as $key => $entry) {
            if (! isset($matched[$key])) {
                $stale[] = $entry['attack'] . ' ' . $entry['method'] . ' ' . $entry['path'] . ' ' . $entry['probe'];
            }
        }

        return [
            'fresh' => $fresh,
            'known' => \array_values(\array_filter($findings, static fn (Finding $finding): bool => isset($known[$finding->key()]))),
            'stale' => $stale,
            'unreasoned' => $unreasoned,
        ];
    }

    /**
     * @param list<string> $routes
     * @return array{fresh: list<string>, stale: list<string>}
     */
    public function partitionRoutes(array $routes): array
    {
        $listed = $this->routes();
        if ($listed === []) {
            return ['fresh' => [], 'stale' => []];
        }

        return [
            'fresh' => \array_values(\array_diff($routes, $listed)),
            'stale' => \array_values(\array_diff($listed, $routes)),
        ];
    }

    /**
     * @param list<string> $routes
     * @param list<Finding> $findings
     */
    public static function write(array $routes, array $findings, string $path = self::PATH): void
    {
        $existing = self::load($path);
        $reasons = $existing->findings();
        $kept = [];
        foreach ($existing->rawFindings() as $entry) {
            $key = (new Finding(
                attack: (string) ($entry['attack'] ?? ''),
                method: (string) ($entry['method'] ?? ''),
                path: (string) ($entry['path'] ?? ''),
                probe: (string) ($entry['probe'] ?? ''),
                detail: (string) ($entry['detail'] ?? ''),
                severity: (string) ($entry['severity'] ?? Finding::ERROR),
            ))->key();
            $kept[$key] = $entry;
        }

        $rows = [];
        $seen = [];
        foreach ($findings as $finding) {
            $seen[$finding->key()] = true;
            $previous = $kept[$finding->key()] ?? [];
            $rows[] = [
                'attack' => $finding->attack,
                'method' => $finding->method,
                'path' => $finding->path,
                'probe' => $finding->probe,
                'severity' => $finding->severity,
                'detail' => $finding->detail,
                'reason' => (string) ($reasons[$finding->key()]['reason'] ?? $previous['reason'] ?? ''),
            ];
        }

        foreach ($kept as $key => $entry) {
            if (isset($seen[$key])) {
                continue;
            }
            $rows[] = [
                'attack' => (string) ($entry['attack'] ?? ''),
                'method' => (string) ($entry['method'] ?? ''),
                'path' => (string) ($entry['path'] ?? ''),
                'probe' => (string) ($entry['probe'] ?? ''),
                'severity' => (string) ($entry['severity'] ?? Finding::ERROR),
                'detail' => (string) ($entry['detail'] ?? ''),
                'reason' => (string) ($entry['reason'] ?? ''),
            ];
        }

        \usort($rows, static function (array $left, array $right): int {
            return [$left['attack'], $left['method'], $left['path'], $left['probe']]
                <=> [$right['attack'], $right['method'], $right['path'], $right['probe']];
        });

        $document = [
            'version' => 1,
            'routes' => \array_values($routes),
            'findings' => $rows,
        ];
        $json = \json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        if (\file_put_contents($path, $json) === false) {
            throw new \RuntimeException('Could not write security baseline: ' . $path);
        }

        if ($path === self::PATH && \is_dir('/storage/cache') && \is_writable('/storage/cache')) {
            \file_put_contents(self::CACHE_PATH, $json);
            \fwrite(
                STDOUT,
                'Also wrote ' . self::CACHE_PATH . ". If tests/ is not bind-mounted, copy it out:\n"
                . '  docker compose cp appwrite:' . self::CACHE_PATH . " tests/e2e/Security/baseline.json\n"
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rawFindings(): array
    {
        $entries = $this->document['findings'] ?? [];
        if (! \is_array($entries)) {
            return [];
        }

        $rows = [];
        foreach ($entries as $entry) {
            if (\is_array($entry)) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }
}
