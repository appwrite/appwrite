<?php

namespace Appwrite\Installer;

/**
 * Usage report for a self-hosted install or upgrade.
 *
 * Every entry point (web installer, interactive CLI, headless CLI) builds one of
 * these, so the decision to opt out and the payload shape live here rather than
 * on the task that happens to run.
 */
final readonly class Report
{
    public const string ACTION_INSTALL = 'install';
    public const string ACTION_UPGRADE = 'upgrade';

    public const string SOURCE_WEB = 'web';
    public const string SOURCE_CLI = 'cli';
    public const string SOURCE_CLI_HEADLESS = 'cli-headless';

    public const string ENVIRONMENT_PRODUCTION = 'production';

    public function __construct(
        public string $action,
        public string $source,
        public string $version,
        public string $channel,
        public string $topology,
        public string $domain,
        public string $database,
        public bool $started,
        public ?string $name,
        public ?string $email,
        public ?string $ip,
        public string $os,
        public string $arch,
        public ?int $cpus,
        public ?int $ram,
    ) {
    }

    /**
     * True when this install must not be reported: the user set DO_NOT_TRACK
     * (https://donottrack.sh/), the install is a developer checkout of this
     * repository, or the environment is not production.
     */
    public static function optedOut(string $doNotTrack, string $environment, bool $local): bool
    {
        if ($local) {
            return true;
        }

        if (\in_array(\strtolower($doNotTrack), ['1', 'true', 'yes'], true)) {
            return true;
        }

        return $environment !== self::ENVIRONMENT_PRODUCTION;
    }

    /**
     * A loopback or unspecified host is a local or test instance: it never
     * resolves to anything worth reporting and its account stays private.
     */
    public static function isLoopback(string $domain): bool
    {
        return $domain === 'localhost'
            || \str_starts_with($domain, '127.')
            || \str_starts_with($domain, '0.0.0.0');
    }

    /**
     * The User-Agent sent with the report, carrying the entry point, channel,
     * topology and whether the containers were started:
     * `Appwrite-Installer/2.0.0 (cli-headless; stable; combined; started)`.
     */
    public function userAgent(): string
    {
        $facts = [$this->source, $this->channel, $this->topology, $this->started ? 'started' : 'not-started'];

        return 'Appwrite-Installer/' . $this->version . ' (' . \implode('; ', $facts) . ')';
    }

    /**
     * Only fresh installs are sent, and only with the version, domain and
     * database set: the installations endpoint drops rows missing any of
     * them, so sending those would just spend the rate limit.
     */
    public function sendable(): bool
    {
        return $this->action === self::ACTION_INSTALL
            && $this->version !== ''
            && $this->domain !== ''
            && $this->database !== '';
    }

    /**
     * Body for `POST /v1/growth/installations`. Every field is optional, so
     * unknown values are left out rather than sent empty.
     *
     * @return array<string, string|int>
     */
    public function payload(): array
    {
        return \array_filter([
            'name' => $this->name,
            'email' => $this->email,
            'version' => $this->version,
            'domain' => $this->domain,
            'database' => $this->database,
            'hostIp' => $this->ip,
            'userAgent' => $this->userAgent(),
            'os' => $this->os,
            'arch' => $this->arch,
            'cpus' => $this->cpus,
            'ram' => $this->ram,
        ], fn (string|int|null $value): bool => $value !== null && $value !== '');
    }
}
