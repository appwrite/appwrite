<?php

namespace Appwrite\SDK\Specification;

use Appwrite\Utopia\Response\Model;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Route;

abstract class Format
{
    protected App $app;

    /**
     * @var array<Route>
     */
    protected array $routes;

    /**
     * @var array<Model>
     */
    protected array $models;

    protected array $services;
    protected array $keys;
    protected int $authCount;
    protected string $platform;
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

    public function __construct(App $app, array $services, array $routes, array $models, array $keys, int $authCount, string $platform)
    {
        $this->app = $app;
        $this->services = $services;
        $this->routes = $routes;
        $this->models = $models;
        $this->keys = $keys;
        $this->authCount = $authCount;
        $this->platform = $platform;
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

    /**
     * Set Services.
     *
     * Set services value
     *
     * @param array $services
     *
     * @return self
     */
    public function setServices(array $services): self
    {
        $this->services = $services;
        return $this;
    }

    /**
     * Set Services.
     *
     * Get services value
     *
     * @param array $services
     *
     * @return self
     */
    public function getServices(): array
    {
        return $this->services;
    }

    protected function getRequestEnumName(string $service, string $method, string $param): ?string
    {
        /* `$service` is `$namespace` */
        switch ($service) {
            case 'proxy':
                switch ($method) {
                    case 'createRedirectRule':
                        switch ($param) {
                            case 'resourceType':
                                return 'ProxyResourceType';
                        }
                        break;
                }
                break;
            case 'console':
                switch ($method) {
                    case 'getResource':
                        switch ($param) {
                            case 'type':
                                return 'ConsoleResourceType';
                            case 'value':
                                return 'ConsoleResourceValue';
                        }
                        break;
                }
                break;
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
                    case 'getScreenshot':
                        switch ($param) {
                            case 'permissions':
                                return 'BrowserPermission';
                        }
                        break;
                }
                break;
            case 'databases':
                switch ($method) {
                    case 'getUsage':
                    case 'listUsage':
                    case 'getCollectionUsage':
                        switch ($param) {
                            case 'range':
                                return 'UsageRange';
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
            case 'tablesDB':
                switch ($method) {
                    case 'getUsage':
                    case 'listUsage':
                    case 'getTableUsage':
                        switch ($param) {
                            case 'range':
                                return 'UsageRange';
                        }
                        break;
                    case 'createRelationshipColumn':
                        switch ($param) {
                            case 'type':
                                return 'RelationshipType';
                            case 'onDelete':
                                return 'RelationMutate';
                        }
                        break;
                    case 'updateRelationshipColumn':
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
                    case 'listUsage':
                        switch ($param) {
                            case 'range':
                                return 'UsageRange';
                        }
                        break;
                    case 'createExecution':
                        switch ($param) {
                            case 'method':
                                return 'ExecutionMethod';
                        }
                        break;
                    case 'getDeploymentDownload':
                        switch ($param) {
                            case 'type':
                                return 'DeploymentDownloadType';
                        }
                        break;
                    case 'createVcsDeployment':
                        switch ($param) {
                            case 'type':
                                return 'VCSReferenceType';
                        }
                        break;
                    case 'createTemplateDeployment':
                        switch ($param) {
                            case 'type':
                                return 'TemplateReferenceType';
                        }
                        break;
                }
                break;
            case 'sites':
                switch ($method) {
                    case 'getDeploymentDownload':
                        switch ($param) {
                            case 'type':
                                return 'DeploymentDownloadType';
                        }
                        break;
                    case 'getUsage':
                    case 'listUsage':
                        switch ($param) {
                            case 'range':
                                return 'UsageRange';
                        }
                        break;
                    case 'createVcsDeployment':
                        switch ($param) {
                            case 'type':
                                return 'VCSReferenceType';
                        }
                        break;
                    case 'createTemplateDeployment':
                        switch ($param) {
                            case 'type':
                                return 'TemplateReferenceType';
                        }
                        break;
                }
                break;
            case 'vcs':
                switch ($method) {
                    case 'createRepositoryDetection':
                    case 'listRepositories':
                        switch ($param) {
                            case 'type':
                                return 'VCSDetectionType';
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
                            case 'priority':
                                return 'MessagePriority';
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
                                return 'UsageRange';
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
                                return 'UsageRange';
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

    public function getRequestEnumKeys(string $service, string $method, string $param): array
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
                    case 'listUsage':
                    case 'getCollectionUsage':
                        // Range Enum Keys
                        return ['Twenty Four Hours', 'Thirty Days', 'Ninety Days'];
                }
                break;
            case 'tablesDB':
                switch ($method) {
                    case 'getUsage':
                    case 'listUsage':
                    case 'getTableUsage':
                        // Range Enum Keys
                        return ['Twenty Four Hours', 'Thirty Days', 'Ninety Days'];
                }
                break;
            case 'proxy':
                switch ($method) {
                    case 'createRedirectRule':
                        switch ($param) {
                            case 'statusCode':
                                return ['Moved Permanently 301', 'Found 302', 'Temporary Redirect 307', 'Permanent Redirect 308'];
                            case 'resourceType':
                                return ['Site', 'Function'];
                        }
                        break;
                }
                break;
            case 'sites':
            case 'functions':
                switch ($method) {
                    case 'getUsage':
                    case 'listUsage':
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

    public function getResponseEnumName(string $model, string $param): ?string
    {
        switch ($model) {
            case 'attributeString':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'attributeInteger':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'attributeFloat':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'attributeBoolean':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'attributeEmail':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'attributeEnum':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'attributeIp':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'attributeUrl':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'attributeDatetime':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'attributeRelationship':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'attributePoint':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'attributeLine':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'attributePolygon':
                switch ($param) {
                    case 'status':
                        return 'AttributeStatus';
                }
                break;
            case 'columnString':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'columnInteger':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'columnFloat':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'columnBoolean':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'columnEmail':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'columnEnum':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'columnIp':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'columnUrl':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'columnDatetime':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'columnRelationship':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'columnPoint':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'columnLine':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'columnPolygon':
                switch ($param) {
                    case 'status':
                        return 'ColumnStatus';
                }
                break;
            case 'healthStatus':
                switch ($param) {
                    case 'status':
                        return 'HealthCheckStatus';
                }
                break;
        }
        return null;
    }

    protected function getNestedModels(Model $model, array &$usedModels): void
    {
        foreach ($model->getRules() as $rule) {
            if (!in_array($model->getType(), $usedModels)) {
                continue;
            }
            $types = (array)$rule['type'];
            foreach ($types as $ruleType) {
                if (!in_array($ruleType, ['string', 'integer', 'boolean', 'json', 'float'])) {
                    $usedModels[] = $ruleType;
                    foreach ($this->models as $m) {
                        if ($m->getType() === $ruleType) {
                            $this->getNestedModels($m, $usedModels);
                        }
                    }
                }
            }
        }
    }
}
