<?php

namespace Appwrite\SDK\Specification;

use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;
use Utopia\DI\Container;
use Utopia\Http\Route;

abstract class Format
{
    protected Container $container;

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

    private const array OAUTH_PROVIDER_BLACKLIST = [
        [
            'namespace' => 'account',
            'methods' => [
                'createOAuth2Session',
                'createOAuth2Token',
                'updateMagicURLSession'
            ],
            'parameter' => 'provider',
            'excludeKeys' => [
                'mock',
                'mock-unverified'
            ],
        ],
        [
            'namespace' => 'projects',
            'methods' => [
                'updateOAuth2'
            ],
            'parameter' => 'provider',
            'excludeKeys' => [
                'mock',
                'mock-unverified'
            ],
        ],
    ];

    private const array PROVIDER_USAGE_BLACKLIST = [
        [
            'namespace' => 'users',
            'methods' => [
                'getUsage'
            ],
            'parameter' => 'provider',
            'exclude' => true, /* fully excluded */
        ],
    ];

    private const array REQUEST_PARAMETER_OVERRIDES = [
        [
            'namespace' => 'project',
            'methods' => [
                'createWebPlatform',
                'updateWebPlatform',
            ],
            'parameter' => 'hostname',
            'required' => true,
        ],
    ];

    protected array $enumBlacklist = [];

    public function __construct(Container $container, array $services, array $routes, array $models, array $keys, int $authCount, string $platform)
    {
        $this->container = $container;
        $this->services = $services;
        $this->routes = $routes;
        $this->models = $models;
        $this->keys = $keys;
        $this->authCount = $authCount;
        $this->platform = $platform;

        $this->enumBlacklist = $this->buildEnumBlacklist();
    }

    protected function buildEnumBlacklist(): array
    {
        $blacklist = [];

        foreach (self::OAUTH_PROVIDER_BLACKLIST as $config) {
            foreach ($config['methods'] as $method) {
                $entry = [
                    'namespace' => $config['namespace'],
                    'method' => $method,
                    'parameter' => $config['parameter'],
                ];
                if (isset($config['excludeKeys'])) {
                    $entry['excludeKeys'] = $config['excludeKeys'];
                }
                if (isset($config['exclude'])) {
                    $entry['exclude'] = $config['exclude'];
                }
                $blacklist[] = $entry;
            }
        }

        foreach (self::PROVIDER_USAGE_BLACKLIST as $config) {
            foreach ($config['methods'] as $method) {
                $entry = [
                    'namespace' => $config['namespace'],
                    'method' => $method,
                    'parameter' => $config['parameter'],
                ];
                if (isset($config['excludeKeys'])) {
                    $entry['excludeKeys'] = $config['excludeKeys'];
                }
                if (isset($config['exclude'])) {
                    $entry['exclude'] = $config['exclude'];
                }
                $blacklist[] = $entry;
            }
        }

        return $blacklist;
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
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * @param list<string> $injections
     * @return array<string, mixed>
     */
    protected function getResources(array $injections): array
    {
        $resources = [];

        foreach ($injections as $name) {
            $resources[$name] = $this->container->get($name);
        }

        return $resources;
    }

    protected function getValidator(array $param): mixed
    {
        return \is_callable($param['validator'])
            ? ($param['validator'])(...$this->getResources($param['injections'] ?? []))
            : $param['validator'];
    }

    protected function getDescriptionContents(?string $description): string
    {
        if ($description === null || $description === '') {
            return '';
        }

        if (!\str_ends_with($description, '.md')) {
            return $description;
        }

        $contents = @\file_get_contents($description);

        if ($contents === false) {
            throw new \RuntimeException('Documentation file not found or unreadable: ' . $description);
        }

        return $contents;
    }

    /**
     * @param array<Model> $models
     * @return array<string, mixed>|null
     */
    protected function getDiscriminator(array $models, string $refPrefix): ?array
    {
        if (\count($models) < 2) {
            return null;
        }

        $candidateKeys = \array_keys($models[0]->conditions);

        foreach (\array_slice($models, 1) as $model) {
            $candidateKeys = \array_values(\array_intersect($candidateKeys, \array_keys($model->conditions)));
        }

        if (empty($candidateKeys)) {
            return null;
        }

        foreach ($candidateKeys as $key) {
            $mapping = [];
            $isValid = true;

            foreach ($models as $model) {
                $rules = $model->getRules();
                $condition = $model->conditions[$key] ?? null;

                if (!isset($rules[$key]) || ($rules[$key]['required'] ?? false) !== true) {
                    $isValid = false;
                    break;
                }

                if (!\is_array($condition)) {
                    if (!\is_scalar($condition)) {
                        $isValid = false;
                        break;
                    }

                    $values = [$condition];
                } else {
                    if ($condition === []) {
                        $isValid = false;
                        break;
                    }

                    $values = $condition;
                    $hasInvalidValue = false;

                    foreach ($values as $value) {
                        if (!\is_scalar($value)) {
                            $hasInvalidValue = true;
                            break;
                        }
                    }

                    if ($hasInvalidValue) {
                        $isValid = false;
                        break;
                    }
                }

                if (isset($rules[$key]['enum']) && \is_array($rules[$key]['enum'])) {
                    $values = \array_values(\array_filter(
                        $values,
                        fn (mixed $value) => \in_array($value, $rules[$key]['enum'], true)
                    ));
                }

                if ($values === []) {
                    $isValid = false;
                    break;
                }

                $ref = $refPrefix . $model->getType();

                foreach ($values as $value) {
                    $mappingKey = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

                    if (isset($mapping[$mappingKey]) && $mapping[$mappingKey] !== $ref) {
                        $isValid = false;
                        break;
                    }

                    $mapping[$mappingKey] = $ref;
                }

                if (!$isValid) {
                    break;
                }
            }

            if (!$isValid || $mapping === []) {
                continue;
            }

            return [
                'propertyName' => $key,
                'mapping' => $mapping,
            ];
        }

        // Single-key failed — try compound discriminator
        return $this->getCompoundDiscriminator($models, $refPrefix);
    }

    /**
     * @param array<Model> $models
     * @return array<string, mixed>|null
     */
    private function getCompoundDiscriminator(array $models, string $refPrefix): ?array
    {
        $allKeys = [];
        foreach ($models as $model) {
            foreach (\array_keys($model->conditions) as $key) {
                if (!\in_array($key, $allKeys, true)) {
                    $allKeys[] = $key;
                }
            }
        }

        if (\count($allKeys) < 2) {
            return null;
        }

        $primaryKey = $allKeys[0];
        $primaryMapping = [];
        $compoundMapping = [];

        foreach ($models as $model) {
            $rules = $model->getRules();
            $conditions = [];

            foreach ($model->conditions as $key => $condition) {
                if (!isset($rules[$key]) || ($rules[$key]['required'] ?? false) !== true) {
                    return null;
                }

                if (!\is_scalar($condition)) {
                    return null;
                }

                $conditions[$key] = \is_bool($condition) ? ($condition ? 'true' : 'false') : (string) $condition;
            }

            if (empty($conditions)) {
                return null;
            }

            $ref = $refPrefix . $model->getType();
            $compoundMapping[$ref] = $conditions;

            // Best-effort single-key mapping — last model with this value wins (fallback)
            if (isset($conditions[$primaryKey])) {
                $primaryMapping[$conditions[$primaryKey]] = $ref;
            }
        }

        // Verify compound uniqueness
        $seen = [];
        foreach ($compoundMapping as $conditions) {
            $sig = \json_encode($conditions, JSON_THROW_ON_ERROR);
            if (isset($seen[$sig])) {
                return null;
            }
            $seen[$sig] = true;
        }

        return \array_filter([
            'propertyName' => $primaryKey,
            'mapping' => !empty($primaryMapping) ? $primaryMapping : null,
            'x-propertyNames' => $allKeys,
            'x-mapping' => $compoundMapping,
        ]);
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
                            case 'output':
                                return 'ImageFormat';
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
                                return 'DatabasesIndexType';
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
                                return 'TablesDBIndexType';
                            case 'orders':
                                return 'OrderBy';
                        }
                }
                break;
            case 'documentsDB':
                switch ($method) {
                    case 'getUsage':
                    case 'listUsage':
                    case 'getCollectionUsage':
                        switch ($param) {
                            case 'range':
                                return 'UsageRange';
                        }
                        break;
                    case 'createIndex':
                        switch ($param) {
                            case 'type':
                                return 'DocumentsDBIndexType';
                            case 'orders':
                                return 'OrderBy';
                        }
                }
                break;
            case 'vectorsDB':
                switch ($method) {
                    case 'getUsage':
                    case 'listUsage':
                    case 'getCollectionUsage':
                        switch ($param) {
                            case 'range':
                                return 'UsageRange';
                        }
                        break;
                    case 'createIndex':
                        switch ($param) {
                            case 'type':
                                return 'VectorsDBIndexType';
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
            case 'migrations':
                switch ($method) {
                    case 'createAppwriteMigration':
                    case 'getAppwriteReport':
                        switch ($param) {
                            case 'resources':
                                return 'AppwriteMigrationResource';
                        }
                        break;
                    case 'createFirebaseMigration':
                    case 'getFirebaseReport':
                        switch ($param) {
                            case 'resources':
                                return 'FirebaseMigrationResource';
                        }
                        break;
                    case 'createSupabaseMigration':
                    case 'getSupabaseReport':
                        switch ($param) {
                            case 'resources':
                                return 'SupabaseMigrationResource';
                        }
                        break;
                    case 'createNHostMigration':
                    case 'getNHostReport':
                        switch ($param) {
                            case 'resources':
                                return 'NHostMigrationResource';
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
            case 'documentsDB':
            case 'vectorsDB':
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

    protected function getRequestParameterConfig(string $service, string $method, string $param, bool $optional, bool $nullable, mixed $default): array
    {
        $config = [
            'required' => !$optional,
            'nullable' => $nullable,
        ];

        foreach (self::REQUEST_PARAMETER_OVERRIDES as $override) {
            if (
                $override['namespace'] !== $service
                || !\in_array($method, $override['methods'], true)
                || $override['parameter'] !== $param
            ) {
                continue;
            }

            $config['required'] = $override['required'] ?? $config['required'];
            $config['nullable'] = $override['nullable'] ?? $config['nullable'];
            break;
        }

        $config['emitDefault'] = !$config['required'] && !\is_null($default);

        return $config;
    }

    public function getResponseEnumName(string $model, string $param): ?string
    {
        if ($param === 'type' && \str_starts_with($model, 'platform') && $model !== 'platformList') {
            return 'PlatformType';
        }

        if ($param !== 'status') {
            return null;
        }

        return match (true) {
            $model === 'healthStatus' => 'HealthCheckStatus',
            str_starts_with($model, 'attribute') => 'AttributeStatus',
            str_starts_with($model, 'column') => 'ColumnStatus',
            default => null,
        };
    }

    protected function getNestedModels(Model $model, array &$usedModels): void
    {
        foreach ($model->getRules() as $rule) {
            if (($rule['hidden'] ?? false) === true) {
                continue;
            }
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

    protected function parseDescription(string $description, array $excludedValues): string
    {
        if (empty($excludedValues)) {
            return $description;
        }

        foreach ($excludedValues as $excludedValue) {
            // remove from comma-separated list
            $description = preg_replace(
                '/,\s*' . preg_quote($excludedValue, '/') . '(?=\s*[,.]|$)/',
                '',
                $description
            );
            $description = preg_replace(
                '/(?<=:\s|,\s)' . preg_quote($excludedValue, '/') . '\s*,\s*/',
                '',
                $description
            );
        }

        // clean up double commas and extra spaces
        $description = preg_replace('/,\s*,/', ',', $description);
        $description = preg_replace('/\s+/', ' ', $description);

        return trim($description);
    }
}
