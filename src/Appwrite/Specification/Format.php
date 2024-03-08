<?php

namespace Appwrite\Specification;

use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;
use Utopia\Http\Http;
use Utopia\Http\Route;

abstract class Format
{
    protected Http $http;

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

    /*
     * Blacklist to omit the enum types for the given route's parameter
     */
    protected array $enumBlacklist = [
        [
            'namespace' => 'users',
            'method' => 'getUsage',
            'parameter' => 'provider'
        ]
    ];

    public function __construct(Http $http, array $services, array $routes, array $models, array $keys, int $authCount)
    {
        $this->http = $http;
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
                    case 'createOAuth2Token':
                        switch ($param) {
                            case 'provider':
                                return 'OAuthProvider';
                        }
                        break;
                    case 'createMfaAuthenticator':
                    case 'updateMfaAuthenticator':
                    case 'deleteMfaAuthenticator':
                        switch ($param) {
                            case 'type':
                                return 'AuthenticatorType';
                        }
                        break;
                    case 'createMfaChallenge':
                        switch ($param) {
                            case 'factor':
                                return 'AuthenticationFactor';
                        }
                        break;
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
            case 'databases':
                switch ($method) {
                    case 'getUsage':
                    case 'getCollectionUsage':
                    case 'getDatabaseUsage':
                        switch ($param) {
                            case 'range':
                                return 'DatabaseUsageRange';
                        }
                        break;
                    case 'createRelationshipAttribute':
                        switch ($param) {
                            case 'type':
                                return 'RelationshipType';
                            case 'onDelete':
                                return 'RelationMutate';
                        }
                        break;
                    case 'updateRelationshipAttribute':
                        switch ($param) {
                            case 'onDelete':
                                return 'RelationMutate';
                        }
                        break;
                    case 'createIndex':
                        switch ($param) {
                            case 'type':
                                return 'IndexType';
                            case 'orders':
                                return 'OrderBy';
                        }
                }
                break;
            case 'functions':
                switch ($method) {
                    case 'getUsage':
                    case 'getFunctionUsage':
                        switch ($param) {
                            case 'range':
                                return 'FunctionUsageRange';
                        }
                        break;
                    case 'createExecution':
                        switch ($param) {
                            case 'method':
                                return 'ExecutionMethod';
                        }
                        break;
                }
                break;
            case 'messaging':
                switch ($method) {
                    case 'getUsage':
                        switch ($param) {
                            case 'period':
                                return 'MessagingUsageRange';
                        }
                        break;
                    case 'createSms':
                    case 'createPush':
                    case 'createEmail':
                    case 'updateSms':
                    case 'updatePush':
                    case 'updateEmail':
                        switch ($param) {
                            case 'status':
                                return 'MessageStatus';
                        }
                        break;
                    case 'createSmtpProvider':
                    case 'updateSmtpProvider':
                        switch ($param) {
                            case 'encryption':
                                return 'SmtpEncryption';
                        }
                        break;
                }
                break;
            case 'project':
                switch ($method) {
                    case 'getUsage':
                        switch ($param) {
                            case 'period':
                                return 'ProjectUsageRange';
                        }
                        break;
                }
                break;
            case 'projects':
                switch ($method) {
                    case 'getEmailTemplate':
                    case 'updateEmailTemplate':
                    case 'deleteEmailTemplate':
                        switch ($param) {
                            case 'type':
                                return 'EmailTemplateType';
                            case 'locale':
                                return 'EmailTemplateLocale';
                        }
                        break;
                    case 'getSmsTemplate':
                    case 'updateSmsTemplate':
                    case 'deleteSmsTemplate':
                        switch ($param) {
                            case 'type':
                                return 'SmsTemplateType';
                            case 'locale':
                                return 'SmsTemplateLocale';
                        }
                        break;
                    case 'createPlatform':
                        switch ($param) {
                            case 'type':
                                return 'PlatformType';
                        }
                        break;
                    case 'createSmtpTest':
                    case 'updateSmtp':
                        switch ($param) {
                            case 'secure':
                                return 'SMTPSecure';
                        }
                        break;
                    case 'updateOAuth2':
                        switch ($param) {
                            case 'provider':
                                return 'OAuthProvider';
                        }
                        break;
                    case 'updateAuthStatus':
                        switch ($param) {
                            case 'method':
                                return 'AuthMethod';
                        }
                        break;
                    case 'updateServiceStatus':
                        switch ($param) {
                            case 'service':
                                return 'ApiService';
                        }
                        break;
                }
                break;
            case 'storage':
                switch ($method) {
                    case 'getUsage':
                    case 'getBucketUsage':
                        switch ($param) {
                            case 'range':
                                return 'StorageUsageRange';
                        }
                        break;
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
            case 'users':
                switch ($method) {
                    case 'getUsage':
                        switch ($param) {
                            case 'range':
                                return 'UserUsageRange';
                        }
                        break;
                    case 'createMfaAuthenticator':
                    case 'updateMfaAuthenticator':
                    case 'deleteMfaAuthenticator':
                        switch ($param) {
                            case 'type':
                                return 'AuthenticatorType';
                        }
                        break;
                    case 'createTarget':
                        switch ($param) {
                            case 'providerType':
                                return 'MessagingProviderType';
                        }
                        break;
                    case 'createSHAUser':
                        switch ($param) {
                            case 'passwordVersion':
                                return 'PasswordHash';
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
                        return ['Twenty Four Hours', 'Thirty Days', 'Ninety Days'];
                }
                break;
            case 'functions':
                switch ($method) {
                    case 'getUsage':
                    case 'getFunctionUsage':
                        // Range Enum Keys
                        return ['Twenty Four Hours', 'Thirty Days', 'Ninety Days'];
                }
                break;
            case 'users':
                switch ($method) {
                    case 'getUsage':
                        // Range Enum Keys
                        if ($param == 'range') {
                            return ['Twenty Four Hours', 'Thirty Days', 'Ninety Days'];
                        }
                }
                break;
            case 'storage':
                switch ($method) {
                    case 'getUsage':
                    case 'getBucketUsage':
                        // Range Enum Keys
                        return ['Twenty Four Hours', 'Thirty Days', 'Ninety Days'];
                }
                break;
            case 'project':
                switch ($method) {
                    case 'getUsage':
                        // Range Enum Keys
                        return ['One Hour', 'One Day'];
                }
                break;
        }
        return $values;
    }
}
