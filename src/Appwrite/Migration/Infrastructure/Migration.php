<?php

namespace Appwrite\Migration\Infrastructure;

use Utopia\Console;

/**
 * A change to an installation's infrastructure, rather than to the data inside it.
 *
 * Data migrations run against a database once the containers are up. Some changes cannot
 * wait that long: renaming a volume, moving a file the compose file mounts, or anything
 * else the containers depend on before they can start. Those run here, during upgrade,
 * after the new compose file and .env are written and before anything is started.
 *
 * Each subclass carries the infrastructure changes introduced by one release, and runs
 * when an installation crosses that release. Register it in self::$versions.
 */
abstract class Migration
{
    /**
     * Release that introduced each set of changes, mapped to the class carrying them.
     *
     * @var array<string, string>
     */
    public static array $versions = [
        '2.0.0' => 'V1',
    ];

    /**
     * Resolved environment for the installation being upgraded.
     *
     * @var array<string, mixed>
     */
    protected array $env = [];

    protected string $path = '';

    /**
     * Migrations to run when moving between two releases, oldest first.
     *
     * A release is crossed when it is newer than the installed version and no newer than
     * the one being installed, so re-running an upgrade replays nothing already applied.
     *
     * @return array<int, Migration>
     */
    final public static function between(string $from, string $to): array
    {
        $from = self::normalize($from);
        $to = self::normalize($to);

        $versions = self::$versions;
        \uksort($versions, static fn (string $a, string $b): int => \version_compare($a, $b));

        $migrations = [];
        foreach ($versions as $version => $class) {
            if (\version_compare($from, $version, '>=') || \version_compare($to, $version, '<')) {
                continue;
            }

            $name = __NAMESPACE__ . '\\Version\\' . $class;
            if (!\class_exists($name)) {
                Console::warning('Skipping unknown infrastructure migration "' . $class . '".');
                continue;
            }

            $migrations[] = new $name();
        }

        return $migrations;
    }

    /**
     * Reduces a version to the release it belongs to.
     *
     * A pre-release carries the same infrastructure as the release it leads to, so
     * 2.0.0-rc.1 has to count as 2.0.0 rather than sorting below it. Anything that is not
     * a version at all -- "latest", "local", a branch name -- is newer than every release,
     * so it runs everything the installed version has not seen.
     */
    private static function normalize(string $version): string
    {
        if (!\preg_match('/^\d+(\.\d+)*/', $version, $matches)) {
            return \PHP_INT_MAX . '.0.0';
        }

        return $matches[0];
    }

    /**
     * @param array<string, mixed> $env
     */
    final public function setContext(array $env, string $path): static
    {
        $this->env = $env;
        $this->path = $path;

        return $this;
    }

    /**
     * Describes the change, for the upgrade log.
     */
    abstract public function getName(): string;

    /**
     * Applies the change. Must be safe to run again: an upgrade can be retried, and a
     * partly applied change must not be made worse by a second pass.
     */
    abstract public function execute(): void;
}
