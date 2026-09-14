<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Migration\Migration;
use Utopia\Console;
use Utopia\Platform\Action;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Release extends Action
{
    /**
     * Release metadata lives in four files that have to agree. Editing them by
     * hand is how a published version ends up disagreeing with the repo, so this
     * writes all four from one version number and tests/unit/Release/MetadataTest.php
     * fails when they drift apart again.
     */
    private const string CONSTANTS = 'app/init/constants.php';
    private const string MIGRATION = 'src/Appwrite/Migration/Migration.php';
    private const string COMPOSE = 'docker-compose.yml';

    private const array READMES = ['README.md', 'README-CN.md'];

    private string $root;

    public static function getName(): string
    {
        return 'release';
    }

    public function __construct()
    {
        $this->root = (string) \realpath(__DIR__ . '/../../../..');

        $this
            ->desc('Write the release metadata for a version')
            ->param('version', '', new Text(32), 'Version being released, e.g. 2.0.2')
            ->param('migration', '', new Text(length: 8, min: 0), 'Migration class for this version. Defaults to the previous version\'s class, which is what a patch must use.', true)
            ->param('console', '', new Text(length: 32, min: 0), 'Console image tag to pin (appwrite/new). Left alone when omitted.', true)
            ->param('dry-run', 'N', new WhiteList(['Y', 'N']), 'Print the changes without writing them', true)
            ->callback($this->action(...));
    }

    public function action(string $version, string $migration, string $console, string $dryRun): void
    {
        if (\preg_match('/^\d+\.\d+\.\d+(-rc\.\d+)?$/', $version) !== 1) {
            Console::error("'{$version}' is not an X.Y.Z or X.Y.Z-rc.N version.");

            return;
        }

        $current = APP_VERSION_STABLE;

        if (\version_compare($version, $current, '<=')) {
            Console::error("{$version} is not newer than the current {$current}.");

            return;
        }

        $previous = \array_key_last(Migration::$versions);
        $migration = $migration !== '' ? $migration : (string) Migration::$versions[$previous];

        if ($this->isPatch($current, $version) && $migration !== (string) Migration::$versions[$previous]) {
            Console::error(
                "{$version} is a patch of {$current}, so it must reuse {$previous}'s migration "
                . "(" . Migration::$versions[$previous] . "), not {$migration}. A fix that needs a "
                . "schema change is a minor -- see the Releases section of AGENTS.md."
            );

            return;
        }

        if (!\class_exists('Appwrite\\Migration\\Version\\' . $migration)) {
            Console::error("Migration class {$migration} does not exist.");

            return;
        }

        $changes = [
            ...$this->stableVersion($current, $version),
            ...$this->readmePins($current, $version),
            ...$this->migrationVersion($version, $migration),
            ...($console !== '' ? $this->consolePin($console) : []),
        ];

        if ($changes === []) {
            Console::success("Everything already reads {$version}; nothing to write.");

            return;
        }

        Console::log("Releasing {$current} -> {$version} (migration {$migration})\n");

        foreach ($changes as $file => $change) {
            Console::log("  {$file}");
            Console::log("    {$change['from']}");
            Console::log("    {$change['to']}\n");

            if ($dryRun === 'N') {
                \file_put_contents($this->path($file), $change['contents']);
            }
        }

        if ($dryRun === 'Y') {
            Console::info('Dry run; nothing written.');

            return;
        }

        Console::success(\count($changes) . ' file(s) written.');
        Console::info('Still by hand: release notes on the changelog, specs if the API changed, and request/response filters for public breaks.');
    }

    private function isPatch(string $current, string $version): bool
    {
        [$currentMajor, $currentMinor] = \explode('.', $current);
        [$major, $minor] = \explode('.', $version);

        return $currentMajor === $major && $currentMinor === $minor;
    }

    private function path(string $file): string
    {
        return $this->root . '/' . $file;
    }

    private function read(string $file): string
    {
        $contents = @\file_get_contents($this->path($file));

        if ($contents === false) {
            throw new \RuntimeException("Cannot read {$file} from {$this->root}.");
        }

        return $contents;
    }

    /**
     * @return array<string, array{from: string, to: string, contents: string}>
     */
    private function stableVersion(string $current, string $version): array
    {
        $contents = $this->read(self::CONSTANTS);
        $from = "const APP_VERSION_STABLE = '{$current}';";
        $to = "const APP_VERSION_STABLE = '{$version}';";

        if (!\str_contains($contents, $from)) {
            throw new \RuntimeException(self::CONSTANTS . " does not declare APP_VERSION_STABLE as '{$current}'.");
        }

        return [self::CONSTANTS => [
            'from' => "- {$from}",
            'to' => "+ {$to}",
            'contents' => \str_replace($from, $to, $contents),
        ]];
    }

    /**
     * @return array<string, array{from: string, to: string, contents: string}>
     */
    private function readmePins(string $current, string $version): array
    {
        $changes = [];

        foreach (self::READMES as $readme) {
            $contents = $this->read($readme);
            $count = 0;
            $updated = \str_replace("appwrite/appwrite:{$current}", "appwrite/appwrite:{$version}", $contents, $count);

            if ($count === 0) {
                throw new \RuntimeException("{$readme} does not pin appwrite/appwrite:{$current}.");
            }

            $changes[$readme] = [
                'from' => "- appwrite/appwrite:{$current} ({$count}x)",
                'to' => "+ appwrite/appwrite:{$version} ({$count}x)",
                'contents' => $updated,
            ];
        }

        return $changes;
    }

    /**
     * @return array<string, array{from: string, to: string, contents: string}>
     */
    private function migrationVersion(string $version, string $migration): array
    {
        $contents = $this->read(self::MIGRATION);

        if (\preg_match("/^\s*'" . \preg_quote($version, '/') . "'\s*=>/m", $contents) === 1) {
            return [];
        }

        $previous = \array_key_last(Migration::$versions);
        $anchor = "'{$previous}' => '" . Migration::$versions[$previous] . "',";

        if (!\str_contains($contents, $anchor)) {
            throw new \RuntimeException(self::MIGRATION . " does not end its \$versions map with {$anchor}.");
        }

        $entry = "'{$version}' => '{$migration}',";

        return [self::MIGRATION => [
            'from' => "  {$anchor}",
            'to' => "+ {$entry}",
            'contents' => \str_replace($anchor, $anchor . "\n        " . $entry, $contents),
        ]];
    }

    /**
     * @return array<string, array{from: string, to: string, contents: string}>
     */
    private function consolePin(string $console): array
    {
        $contents = $this->read(self::COMPOSE);

        if (\preg_match('/image: appwrite\/new:(\S+)/', $contents, $matches) !== 1) {
            throw new \RuntimeException(self::COMPOSE . ' does not pin an appwrite/new image.');
        }

        if ($matches[1] === $console) {
            return [];
        }

        return [self::COMPOSE => [
            'from' => "- image: appwrite/new:{$matches[1]}",
            'to' => "+ image: appwrite/new:{$console}",
            'contents' => \str_replace($matches[0], "image: appwrite/new:{$console}", $contents),
        ]];
    }
}
