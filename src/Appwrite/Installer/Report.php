<?php

namespace Appwrite\Installer;

/**
 * What a self-hosted install or upgrade tells growth.appwrite.io about itself.
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

    public const string ACCOUNT = 'self-hosted';
    public const string CATEGORY = 'self_hosted';
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
     * A loopback or unspecified host never resolves to anything worth reporting.
     */
    public static function isLoopback(string $domain): bool
    {
        return $domain === 'localhost'
            || \str_starts_with($domain, '127.')
            || \str_starts_with($domain, '0.0.0.0');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'action' => $this->action,
            'account' => self::ACCOUNT,
            'url' => 'https://' . $this->domain,
            'category' => self::CATEGORY,
            'label' => self::CATEGORY . '_' . $this->action,
            'version' => $this->version,
            'data' => \json_encode([
                'name' => $this->name,
                'email' => $this->email,
                'domain' => $this->domain,
                'database' => $this->database,
                'source' => $this->source,
                'started' => $this->started,
                'channel' => $this->channel,
                'topology' => $this->topology,
                'ip' => $this->ip,
                'os' => $this->os,
                'arch' => $this->arch,
                'cpus' => $this->cpus,
                'ram' => $this->ram,
            ]),
        ];
    }
}
