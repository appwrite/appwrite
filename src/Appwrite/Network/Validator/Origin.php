<?php

namespace Appwrite\Network\Validator;

use Utopia\Validator;

class Origin extends Validator
{
    const CLIENT_TYPE_UNKNOWN = 'unknown';
    const CLIENT_TYPE_WEB = 'web';
    const CLIENT_TYPE_FLUTTER_IOS = 'flutter-ios';
    const CLIENT_TYPE_FLUTTER_ANDROID = 'flutter-android';
    const CLIENT_TYPE_FLUTTER_MACOS = 'flutter-macos';
    const CLIENT_TYPE_FLUTTER_WINDOWS = 'flutter-windows';
    const CLIENT_TYPE_FLUTTER_LINUX = 'flutter-linux';
    
    const SCHEME_TYPE_HTTP = 'http';
    const SCHEME_TYPE_HTTPS = 'https';
    const SCHEME_TYPE_IOS = 'appwrite-ios';
    const SCHEME_TYPE_ANDROID = 'appwrite-android';
    const SCHEME_TYPE_MACOS = 'appwrite-macos';
    const SCHEME_TYPE_WINDOWS = 'appwrite-windows';
    const SCHEME_TYPE_LINUX = 'appwrite-linux';

    /**
     * @var array
     */
    protected $platforms = [
        self::SCHEME_TYPE_HTTP => 'Web',
        self::SCHEME_TYPE_HTTPS => 'Web',
        self::SCHEME_TYPE_IOS => 'iOS',
        self::SCHEME_TYPE_ANDROID => 'Android',
        self::SCHEME_TYPE_MACOS => 'macOS',
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
                    $this->clients[] = (isset($platform['hostname'])) ? $platform['hostname'] : '';
                    break;
                
                case self::CLIENT_TYPE_FLUTTER_IOS:
                case self::CLIENT_TYPE_FLUTTER_ANDROID:
                case self::CLIENT_TYPE_FLUTTER_MACOS:
                case self::CLIENT_TYPE_FLUTTER_WINDOWS:
                case self::CLIENT_TYPE_FLUTTER_LINUX:
                    $this->clients[] = (isset($platform['key'])) ? $platform['key'] : '';
                    break;
                
                default:
                    # code...
                    break;
            }
        }
    }

    public function getDescription()
    {
        if (!\array_key_exists($this->client, $this->platforms)) {
            return 'Unsupported platform';
        }

        return 'Invalid Origin. Register your new client ('.$this->host.') as a new '
            .$this->platforms[$this->client].' platform on your project console dashboard';
    }

    /**
     * Check if Origin has been whiltlisted
     *  for access to the API
     *
     * @param mixed $origin
     *
     * @return bool
     */
    public function isValid($origin)
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

        if (\in_array($host, $this->clients)) {
            return true;
        }

        return false;
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
