<?php

namespace Appwrite\Specification;

use Utopia\App;
use Utopia\Config\Config;
use Utopia\Route;
use Appwrite\Utopia\Response\Model;

abstract class Format
{
    protected App $app;

    /**
     * @var Route[]
     */
    protected array $routes;

    /**
     * @var Model[]
     */
    protected array $models;

    protected array $services;
    protected array $keys;
    protected int $authCount;
    protected array $params = [
        'name' => '',
        'description' => '',
        'endpoint' => 'https://localhost',
        'version' => '1.0.0',
        'terms' => '',
        'support.email' => '',
        'support.url' => '',
        'contact.name' => '',
        'contact.email' => '',
        'contact.url' => '',
        'license.name' => '',
        'license.url' => '',
    ];

    public function __construct(App $app, array $services, array $routes, array $models, array $keys, int $authCount)
    {
        $this->app = $app;
        $this->services = $services;
        $this->routes = $routes;
        $this->models = $models;
        $this->keys = $keys;
        $this->authCount = $authCount;
    }

    /**
     * Get Name.
     *
     * Get format name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Parse
     *
     * Parses Appwrite App to given format
     *
     * @return array
     */
    abstract public function parse(): array;

    /**
     * Set Param.
     *
     * Set param value
     *
     * @param string $key
     * @param string $value
     *
     * @return self
     */
    public function setParam(string $key, string $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Get Param.
     *
     * Get param value
     *
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    public function getParam(string $key, string $default = ''): string
    {
        return $this->params[$key] ?? $default;
    }

    protected function getEnumName(string $service, string $method, string $param): ?string
    {
        switch ($service) {
            case 'account':
                switch ($method) {
                    case 'createOAuth2Session':
                        return 'Provider';
                }
                break;
            case 'avatars':
                switch ($method) {
                    case 'getBrowser':
                        return 'Browser';
                    case 'getCreditCard':
                        return 'CreditCard';
                    case 'getFlag':
                        return  'Flag';
                }
                break;
            case 'storage':
                switch ($method) {
                    case 'getFilePreview':
                        switch ($param) {
                            case 'gravity':
                                return 'ImageGravity';
                            case 'output':
                                return  'ImageFormat';
                        }
                        break;
                }
                break;
        }
        return null;
    }
    public function getEnumKeys(string $service, string $method, string $param): array
    {
        $values = [];
        switch ($service) {
            case 'avatars':
                switch ($method) {
                    case 'getBrowser':
                        $codes = Config::getParam('avatar-browsers');
                        foreach ($codes as $code => $value) {
                            $values[] = $value['name'];
                        }
                        return $values;
                    case 'getCreditCard':
                        $codes = Config::getParam('avatar-credit-cards');
                        foreach ($codes as $code => $value) {
                            $values[] = $value['name'];
                        }
                        return $values;
                    case 'getFlag':
                        $codes = Config::getParam('avatar-flags');
                        foreach ($codes as $code => $value) {
                            $values[] = $value['name'];
                        }
                        return $values;
                }
                break;
            case 'databases':
                switch ($method) {
                    case 'getUsage':
                    case 'getCollectionUsage':
                    case 'getDatabaseUsage':
                        // Range Enum Keys
                        $values  = ['Twenty Four Hours', 'Seven Days', 'Thirty Days', 'Ninety Days'];
                        return $values;
                }
                break;
            case 'function':
                switch ($method) {
                    case 'getUsage':
                    case 'getFunctionUsage':
                        // Range Enum Keys
                        $values = ['Twenty Four Hours', 'Seven Days', 'Thirty Days', 'Ninety Days'];
                        return $values;
                }
                break;
            case 'users':
                switch ($method) {
                    case 'getUsage':
                    case 'getUserUsage':
                        // Range Enum Keys
                        if ($param == 'range') {
                            $values = ['Twenty Four Hours', 'Seven Days', 'Thirty Days', 'Ninety Days'];
                            return $values;
                        }
                }
                break;
            case 'storage':
                switch ($method) {
                    case 'getUsage':
                    case 'getBucketUsage':
                        // Range Enum Keys
                        $values = ['Twenty Four Hours', 'Seven Days', 'Thirty Days', 'Ninety Days'];
                        return $values;
                }
        }
        return $values;
    }
}
