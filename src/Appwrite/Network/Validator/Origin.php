<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator\Hostname;
use Utopia\Validator;

class Origin extends Validator
{
    public const CLIENT_TYPE_UNKNOWN = 'unknown';
    public const CLIENT_TYPE_WEB = 'web';
    public const CLIENT_TYPE_FLUTTER_IOS = 'flutter-ios';
    public const CLIENT_TYPE_FLUTTER_ANDROID = 'flutter-android';
    public const CLIENT_TYPE_FLUTTER_MACOS = 'flutter-macos';
    public const CLIENT_TYPE_FLUTTER_WINDOWS = 'flutter-windows';
    public const CLIENT_TYPE_FLUTTER_LINUX = 'flutter-linux';
    public const CLIENT_TYPE_FLUTTER_WEB = 'flutter-web';
    public const CLIENT_TYPE_APPLE_IOS = 'apple-ios';
    public const CLIENT_TYPE_APPLE_MACOS = 'apple-macos';
    public const CLIENT_TYPE_APPLE_WATCHOS = 'apple-watchos';
    public const CLIENT_TYPE_APPLE_TVOS = 'apple-tvos';
    public const CLIENT_TYPE_ANDROID = 'android';
    public const CLIENT_TYPE_UNITY = 'unity';


    public const SCHEME_TYPE_HTTP = 'http';
    public const SCHEME_TYPE_HTTPS = 'https';
    public const SCHEME_TYPE_IOS = 'appwrite-ios';
    public const SCHEME_TYPE_MACOS = 'appwrite-macos';
    public const SCHEME_TYPE_WATCHOS = 'appwrite-watchos';
    public const SCHEME_TYPE_TVOS = 'appwrite-tvos';
    public const SCHEME_TYPE_ANDROID = 'appwrite-android';
    public const SCHEME_TYPE_WINDOWS = 'appwrite-windows';
    public const SCHEME_TYPE_LINUX = 'appwrite-linux';

    /**
     * @var array
     */
    protected $platforms = [
        self::SCHEME_TYPE_HTTP => 'Web',
        self::SCHEME_TYPE_HTTPS => 'Web',
        self::SCHEME_TYPE_IOS => 'iOS',
        self::SCHEME_TYPE_MACOS => 'macOS',
        self::SCHEME_TYPE_WATCHOS => 'watchOS',
        self::SCHEME_TYPE_TVOS => 'tvOS',
        self::SCHEME_TYPE_ANDROID => 'Android',
        self::SCHEME_TYPE_WINDOWS => 'Windows',
        self::SCHEME_TYPE_LINUX => 'Linux',
    ];

    /**
     * @var array
     */
    protected $clients = [
    ];

    /**
     * @var string
     */
    protected $client = self::CLIENT_TYPE_UNKNOWN;

    /**
     * @var string
     */
    protected $host = '';

    /**
     * @param string $target
     */
    public function __construct($platforms)
    {
        foreach ($platforms as $platform) {
            $type = (isset($platform['type'])) ? $platform['type'] : '';

            switch ($type) {
                case self::CLIENT_TYPE_WEB:
                case self::CLIENT_TYPE_FLUTTER_WEB:
                    $this->clients[] = (isset($platform['hostname'])) ? $platform['hostname'] : '';
                    break;

                case self::CLIENT_TYPE_FLUTTER_IOS:
                case self::CLIENT_TYPE_FLUTTER_ANDROID:
                case self::CLIENT_TYPE_FLUTTER_MACOS:
                case self::CLIENT_TYPE_FLUTTER_WINDOWS:
                case self::CLIENT_TYPE_FLUTTER_LINUX:
                case self::CLIENT_TYPE_ANDROID:
                case self::CLIENT_TYPE_APPLE_IOS:
                case self::CLIENT_TYPE_APPLE_MACOS:
                case self::CLIENT_TYPE_APPLE_WATCHOS:
                case self::CLIENT_TYPE_APPLE_TVOS:
                    $this->clients[] = (isset($platform['key'])) ? $platform['key'] : '';
                    break;

                default:
                    # code...
                    break;
            }
        }
    }

    public function getDescription(): string
    {
        if (!\array_key_exists($this->client, $this->platforms)) {
            return 'Unsupported platform';
        }

        return 'Invalid Origin. Register your new client (' . $this->host . ') as a new '
            . $this->platforms[$this->client] . ' platform on your project console dashboard';
    }

    /**
     * Check if Origin has been allowed
     *  for access to the API
     *
     * @param mixed $origin
     *
     * @return bool
     */
    public function isValid($origin): bool
    {
        if (!is_string($origin)) {
            return false;
        }

        $scheme = \parse_url($origin, PHP_URL_SCHEME);
        $host = \parse_url($origin, PHP_URL_HOST);

        $this->host = $host;
        $this->client = $scheme;

        if (empty($host)) {
            return true;
        }

        $validator = new Hostname($this->clients);

        return $validator->isValid($host);
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
