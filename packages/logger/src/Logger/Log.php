<?php

namespace Utopia\Logger;

use Exception;
use Utopia\Logger\Log\Breadcrumb;
use Utopia\Logger\Log\User;

class Log
{
    const TYPE_DEBUG = 'debug';

    const TYPE_ERROR = 'error';

    const TYPE_WARNING = 'warning';

    const TYPE_INFO = 'info';

    const TYPE_VERBOSE = 'verbose';

    const ENVIRONMENT_PRODUCTION = 'production';

    const ENVIRONMENT_STAGING = 'staging';

    /**
     * @var float (required, set by default to microtime(true))
     */
    protected float $timestamp;

    /**
     * @var string (required, for example 'Log::TYPE_INFO')
     */
    protected string $type;

    /**
     * @var string (required)
     */
    protected string $message;

    /**
     * @var string (required)
     */
    protected string $version;

    /**
     * @var string (required)
     */
    protected string $environment;

    /**
     * @var string (required)
     */
    protected string $action;

    /**
     * @var array<string, string> (optional)
     */
    protected array $tags = [];

    /**
     * @var array<string, mixed> (optional)
     */
    protected array $extra = [];

    /**
     * @var string (optional)
     */
    protected string $namespace = 'UNKNOWN';

    /**
     * @var string|null (optional)
     */
    protected ?string $server = null;

    /**
     * @var User|null (optional)
     */
    protected ?User $user = null;

    /**
     * @var Breadcrumb[] (optional)
     */
    protected array $breadcrumbs = [];

    /**
     * @var array<string> (optional)
     */
    protected array $masked = [];

    /**
     * Log constructor.
     */
    public function __construct()
    {
        $this->timestamp = \microtime(true);
    }

    /**
     * Set a type
     *
     * @param  string  $type (required, can be 'log', 'error' or 'warning')
     * @return void
     *
     * @throws Exception
     */
    public function setType(string $type): void
    {
        switch ($type) {
            case self::TYPE_DEBUG:
            case self::TYPE_ERROR:
            case self::TYPE_VERBOSE:
            case self::TYPE_INFO:
            case self::TYPE_WARNING:
                break;
            default: throw new Exception('Unsupported log type. Must be one of Log::TYPE_DEBUG, Log::TYPE_ERROR, Log::TYPE_WARNING, Log::TYPE_INFO, Log::VERBOSE.');
        }

        $this->type = $type;
    }

    /**
     * Get the type of this log
     *
     * @return string (can be 'log', 'error' or 'warning')
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set timestamp in seconds when log occurred
     *
     * @param  float  $timestamp (required)
     * @return void
     */
    public function setTimestamp(float $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    /**
     * Get timestamp in seconds when log occurred
     *
     * @return float
     */
    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * Set main message
     *
     * @param  string  $message (required, for example 'Collection abcd1234 not found')
     * @return void
     */
    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    /**
     * Get main message
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Set a custom namespace for easier categorizing
     *
     * @param  string  $namespace (required, for example 'api')
     * @return void
     */
    public function setNamespace(string $namespace): void
    {
        $this->namespace = $namespace;
    }

    /**
     * Set a custom namespace
     *
     * @return string|null
     */
    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    /**
     * Set the action that caused this log
     *
     * @param  string  $action (required, for example 'databaseController.deleteDocument' or 'functionsWorker.executeFunction')
     * @return void
     */
    public function setAction(string $action): void
    {
        $this->action = $action;
    }

    /**
     * Get the action
     *
     * @return string
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Set identificator of server where log happened
     *
     * @param  string|null  $server (required, for example 'digitalocean-us-005')
     * @return void
     */
    public function setServer(?string $server): void
    {
        $this->server = $server;
    }

    /**
     * Get identificator of server where log happened
     *
     * @return string|null
     */
    public function getServer(): ?string
    {
        return $this->server;
    }

    /**
     * Set version of application for easier bug hunting
     *
     * @param  string  $version (required, for example '0.11.2')
     * @return void
     */
    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    /**
     * Get version of application
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Set version of application for easier bug hunting
     *
     * @param  string  $environment (required, can be ENVIRONMENT_PRODUCTION or ENVIRONMENT_STAGING)
     * @return void
     *
     * @throws Exception
     */
    public function setEnvironment(string $environment): void
    {
        switch ($environment) {
            case self::ENVIRONMENT_PRODUCTION:
            case self::ENVIRONMENT_STAGING:
                break;
            default:
                throw new Exception('Unsupported environment of log. Must be one of ENVIRONMENT_PRODUCTION, ENVIRONMENT_STAGING.');
        }

        $this->environment = $environment;
    }

    /**
     * Get version of application
     *
     * @return string
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * Add tags (labels)
     *
     * @param  string  $key
     * @param  string  $value
     * @return void
     */
    public function addTag(string $key, string $value): void
    {
        $this->tags[$key] = $value;
    }

    /**
     * Get tags
     *
     * @return array<string, string>
     */
    public function getTags(): array
    {
        return $this->mask($this->tags);
    }

    /**
     * Add extra metadata of log
     *
     * @param  string  $key
     * @param $value
     * @return void
     */
    public function addExtra(string $key, mixed $value): void
    {
        $this->extra[$key] = $value;
    }

    /**
     * Get extra metadata
     *
     * @return array<string, mixed>
     */
    public function getExtra(): array
    {
        return $this->mask($this->extra);
    }

    /**
     * Set user who caused the log
     *
     * @param  User  $user
     * @return void
     */
    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    /**
     * Get user who caused the log
     *
     * @return User|null
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Add reproduction step
     *
     * @param  Breadcrumb  $breadcrumb
     * @return void
     */
    public function addBreadcrumb(Breadcrumb $breadcrumb): void
    {
        \array_push($this->breadcrumbs, $breadcrumb);
    }

    /**
     * Get reproduction steps
     *
     * @return Breadcrumb[]
     */
    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    /**
     * Set masked fields, which will be replaced by asterisks
     *
     * @param  array<string>  $masked
     * @return void
     */
    public function setMasked(array $masked): void
    {
        $this->masked = $masked;
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $data
     * @return array<string, T|string>
     */
    private function mask(array $data): array
    {
        $masked = [];

        foreach ($data as $key => $value) {
            if (is_string($value) && in_array($key, $this->masked, true)) {
                $masked[$key] = str_repeat('*', strlen($value));
            } elseif (is_array($value)) {
                $maskedValue = $this->mask($value); /** @var T $maskedValue */
                $masked[$key] = $maskedValue;
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }
}
