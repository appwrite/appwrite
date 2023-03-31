<?php

namespace Appwrite\Utopia;

use Exception;
use Utopia\Swoole\Response as SwooleResponse;
use Swoole\Http\Response as SwooleHTTPResponse;
use Utopia\Database\Document;
use Appwrite\Utopia\Response\Filter;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Model\Account;
use Appwrite\Utopia\Response\Model\AlgoArgon2;
use Appwrite\Utopia\Response\Model\AlgoBcrypt;
use Appwrite\Utopia\Response\Model\AlgoMd5;
use Appwrite\Utopia\Response\Model\AlgoPhpass;
use Appwrite\Utopia\Response\Model\AlgoScrypt;
use Appwrite\Utopia\Response\Model\AlgoScryptModified;
use Appwrite\Utopia\Response\Model\AlgoSha;
use Appwrite\Utopia\Response\Model\None;
use Appwrite\Utopia\Response\Model\Any;
use Appwrite\Utopia\Response\Model\Attribute;
use Appwrite\Utopia\Response\Model\AttributeList;
use Appwrite\Utopia\Response\Model\AttributeString;
use Appwrite\Utopia\Response\Model\AttributeInteger;
use Appwrite\Utopia\Response\Model\AttributeFloat;
use Appwrite\Utopia\Response\Model\AttributeBoolean;
use Appwrite\Utopia\Response\Model\AttributeEmail;
use Appwrite\Utopia\Response\Model\AttributeEnum;
use Appwrite\Utopia\Response\Model\AttributeIP;
use Appwrite\Utopia\Response\Model\AttributeURL;
use Appwrite\Utopia\Response\Model\AttributeDatetime;
use Appwrite\Utopia\Response\Model\BaseList;
use Appwrite\Utopia\Response\Model\Collection;
use Appwrite\Utopia\Response\Model\Database;
use Appwrite\Utopia\Response\Model\Continent;
use Appwrite\Utopia\Response\Model\Country;
use Appwrite\Utopia\Response\Model\Currency;
use Appwrite\Utopia\Response\Model\Document as ModelDocument;
use Appwrite\Utopia\Response\Model\Domain;
use Appwrite\Utopia\Response\Model\Error;
use Appwrite\Utopia\Response\Model\ErrorDev;
use Appwrite\Utopia\Response\Model\Execution;
use Appwrite\Utopia\Response\Model\Build;
use Appwrite\Utopia\Response\Model\File;
use Appwrite\Utopia\Response\Model\Bucket;
use Appwrite\Utopia\Response\Model\Func;
use Appwrite\Utopia\Response\Model\Index;
use Appwrite\Utopia\Response\Model\JWT;
use Appwrite\Utopia\Response\Model\Key;
use Appwrite\Utopia\Response\Model\Language;
use Appwrite\Utopia\Response\Model\User;
use Appwrite\Utopia\Response\Model\Session;
use Appwrite\Utopia\Response\Model\Team;
use Appwrite\Utopia\Response\Model\Locale;
use Appwrite\Utopia\Response\Model\Log;
use Appwrite\Utopia\Response\Model\Membership;
use Appwrite\Utopia\Response\Model\Metric;
use Appwrite\Utopia\Response\Model\Permissions;
use Appwrite\Utopia\Response\Model\Phone;
use Appwrite\Utopia\Response\Model\Platform;
use Appwrite\Utopia\Response\Model\Project;
use Appwrite\Utopia\Response\Model\Rule;
use Appwrite\Utopia\Response\Model\Deployment;
use Appwrite\Utopia\Response\Model\Token;
use Appwrite\Utopia\Response\Model\Webhook;
use Appwrite\Utopia\Response\Model\Preferences;
use Appwrite\Utopia\Response\Model\HealthAntivirus;
use Appwrite\Utopia\Response\Model\HealthQueue;
use Appwrite\Utopia\Response\Model\HealthStatus;
use Appwrite\Utopia\Response\Model\HealthTime;
use Appwrite\Utopia\Response\Model\HealthVersion;
use Appwrite\Utopia\Response\Model\Mock; // Keep last
use Appwrite\Utopia\Response\Model\Provider;
use Appwrite\Utopia\Response\Model\Runtime;
use Appwrite\Utopia\Response\Model\UsageBuckets;
use Appwrite\Utopia\Response\Model\UsageCollection;
use Appwrite\Utopia\Response\Model\UsageDatabase;
use Appwrite\Utopia\Response\Model\UsageDatabases;
use Appwrite\Utopia\Response\Model\UsageFunction;
use Appwrite\Utopia\Response\Model\UsageFunctions;
use Appwrite\Utopia\Response\Model\UsageProject;
use Appwrite\Utopia\Response\Model\UsageStorage;
use Appwrite\Utopia\Response\Model\UsageUsers;
use Appwrite\Utopia\Response\Model\Variable;

/**
 * @method int getStatusCode()
 * @method Response setStatusCode(int $code = 200)
 */
class Response extends SwooleResponse
{
    // General
    public const MODEL_NONE = 'none';
    public const MODEL_ANY = 'any';
    public const MODEL_LOG = 'log';
    public const MODEL_LOG_LIST = 'logList';
    public const MODEL_ERROR = 'error';
    public const MODEL_METRIC = 'metric';
    public const MODEL_METRIC_LIST = 'metricList';
    public const MODEL_ERROR_DEV = 'errorDev';
    public const MODEL_BASE_LIST = 'baseList';
    public const MODEL_USAGE_DATABASES = 'usageDatabases';
    public const MODEL_USAGE_DATABASE = 'usageDatabase';
    public const MODEL_USAGE_COLLECTION = 'usageCollection';
    public const MODEL_USAGE_USERS = 'usageUsers';
    public const MODEL_USAGE_BUCKETS = 'usageBuckets';
    public const MODEL_USAGE_STORAGE = 'usageStorage';
    public const MODEL_USAGE_FUNCTIONS = 'usageFunctions';
    public const MODEL_USAGE_FUNCTION = 'usageFunction';
    public const MODEL_USAGE_PROJECT = 'usageProject';

    // Database
    public const MODEL_DATABASE = 'database';
    public const MODEL_DATABASE_LIST = 'databaseList';
    public const MODEL_COLLECTION = 'collection';
    public const MODEL_COLLECTION_LIST = 'collectionList';
    public const MODEL_INDEX = 'index';
    public const MODEL_INDEX_LIST = 'indexList';
    public const MODEL_DOCUMENT = 'document';
    public const MODEL_DOCUMENT_LIST = 'documentList';

    // Database Attributes
    public const MODEL_ATTRIBUTE = 'attribute';
    public const MODEL_ATTRIBUTE_LIST = 'attributeList';
    public const MODEL_ATTRIBUTE_STRING = 'attributeString';
    public const MODEL_ATTRIBUTE_INTEGER = 'attributeInteger';
    public const MODEL_ATTRIBUTE_FLOAT = 'attributeFloat';
    public const MODEL_ATTRIBUTE_BOOLEAN = 'attributeBoolean';
    public const MODEL_ATTRIBUTE_EMAIL = 'attributeEmail';
    public const MODEL_ATTRIBUTE_ENUM = 'attributeEnum';
    public const MODEL_ATTRIBUTE_IP = 'attributeIp';
    public const MODEL_ATTRIBUTE_URL = 'attributeUrl';
    public const MODEL_ATTRIBUTE_DATETIME = 'attributeDatetime';

    // Users
    public const MODEL_ACCOUNT = 'account';
    public const MODEL_USER = 'user';
    public const MODEL_USER_LIST = 'userList';
    public const MODEL_SESSION = 'session';
    public const MODEL_SESSION_LIST = 'sessionList';
    public const MODEL_TOKEN = 'token';
    public const MODEL_JWT = 'jwt';
    public const MODEL_PREFERENCES = 'preferences';

    // Users password algos
    public const MODEL_ALGO_MD5 = 'algoMd5';
    public const MODEL_ALGO_SHA = 'algoSha';
    public const MODEL_ALGO_SCRYPT = 'algoScrypt';
    public const MODEL_ALGO_SCRYPT_MODIFIED = 'algoScryptModified';
    public const MODEL_ALGO_BCRYPT = 'algoBcrypt';
    public const MODEL_ALGO_ARGON2 = 'algoArgon2';
    public const MODEL_ALGO_PHPASS = 'algoPhpass';

    // Storage
    public const MODEL_FILE = 'file';
    public const MODEL_FILE_LIST = 'fileList';
    public const MODEL_BUCKET = 'bucket';
    public const MODEL_BUCKET_LIST = 'bucketList';

    // Locale
    public const MODEL_LOCALE = 'locale';
    public const MODEL_COUNTRY = 'country';
    public const MODEL_COUNTRY_LIST = 'countryList';
    public const MODEL_CONTINENT = 'continent';
    public const MODEL_CONTINENT_LIST = 'continentList';
    public const MODEL_CURRENCY = 'currency';
    public const MODEL_CURRENCY_LIST = 'currencyList';
    public const MODEL_LANGUAGE = 'language';
    public const MODEL_LANGUAGE_LIST = 'languageList';
    public const MODEL_PHONE = 'phone';
    public const MODEL_PHONE_LIST = 'phoneList';

    // Teams
    public const MODEL_TEAM = 'team';
    public const MODEL_TEAM_LIST = 'teamList';
    public const MODEL_MEMBERSHIP = 'membership';
    public const MODEL_MEMBERSHIP_LIST = 'membershipList';

    // Functions
    public const MODEL_FUNCTION = 'function';
    public const MODEL_FUNCTION_LIST = 'functionList';
    public const MODEL_RUNTIME = 'runtime';
    public const MODEL_RUNTIME_LIST = 'runtimeList';
    public const MODEL_DEPLOYMENT = 'deployment';
    public const MODEL_DEPLOYMENT_LIST = 'deploymentList';
    public const MODEL_EXECUTION = 'execution';
    public const MODEL_EXECUTION_LIST = 'executionList';
    public const MODEL_BUILD = 'build';
    public const MODEL_BUILD_LIST = 'buildList';  // Not used anywhere yet
    public const MODEL_FUNC_PERMISSIONS = 'funcPermissions';

    // Project
    public const MODEL_PROJECT = 'project';
    public const MODEL_PROJECT_LIST = 'projectList';
    public const MODEL_WEBHOOK = 'webhook';
    public const MODEL_WEBHOOK_LIST = 'webhookList';
    public const MODEL_KEY = 'key';
    public const MODEL_KEY_LIST = 'keyList';
    public const MODEL_PROVIDER = 'provider';
    public const MODEL_PROVIDER_LIST = 'providerList';
    public const MODEL_PLATFORM = 'platform';
    public const MODEL_PLATFORM_LIST = 'platformList';
    public const MODEL_DOMAIN = 'domain';
    public const MODEL_DOMAIN_LIST = 'domainList';
    public const MODEL_VARIABLE = 'variable';
    public const MODEL_VARIABLE_LIST = 'variableList';

    // Health
    public const MODEL_HEALTH_STATUS = 'healthStatus';
    public const MODEL_HEALTH_VERSION = 'healthVersion';
    public const MODEL_HEALTH_QUEUE = 'healthQueue';
    public const MODEL_HEALTH_TIME = 'healthTime';
    public const MODEL_HEALTH_ANTIVIRUS = 'healthAntivirus';

    // Deprecated
    public const MODEL_PERMISSIONS = 'permissions';
    public const MODEL_RULE = 'rule';
    public const MODEL_TASK = 'task';

    // Tests (keep last)
    public const MODEL_MOCK = 'mock';

    /**
     * @var Filter
     */
    private static $filter = null;

    /**
     * @var array
     */
    protected array $payload = [];

    /**
     * Response constructor.
     *
     * @param float $time
     */
    public function __construct(SwooleHTTPResponse $response)
    {
        if (empty(self::$models)) {
            self
                // General
                ::setModel(new None())
                ::setModel(new Any())
                ::setModel(new Error())
                ::setModel(new ErrorDev())
                // Lists
                ::setModel(new BaseList('Documents List', self::MODEL_DOCUMENT_LIST, 'documents', self::MODEL_DOCUMENT))
                ::setModel(new BaseList('Collections List', self::MODEL_COLLECTION_LIST, 'collections', self::MODEL_COLLECTION))
                ::setModel(new BaseList('Databases List', self::MODEL_DATABASE_LIST, 'databases', self::MODEL_DATABASE))
                ::setModel(new BaseList('Indexes List', self::MODEL_INDEX_LIST, 'indexes', self::MODEL_INDEX))
                ::setModel(new BaseList('Users List', self::MODEL_USER_LIST, 'users', self::MODEL_USER))
                ::setModel(new BaseList('Sessions List', self::MODEL_SESSION_LIST, 'sessions', self::MODEL_SESSION))
                ::setModel(new BaseList('Logs List', self::MODEL_LOG_LIST, 'logs', self::MODEL_LOG))
                ::setModel(new BaseList('Files List', self::MODEL_FILE_LIST, 'files', self::MODEL_FILE))
                ::setModel(new BaseList('Buckets List', self::MODEL_BUCKET_LIST, 'buckets', self::MODEL_BUCKET))
                ::setModel(new BaseList('Teams List', self::MODEL_TEAM_LIST, 'teams', self::MODEL_TEAM))
                ::setModel(new BaseList('Memberships List', self::MODEL_MEMBERSHIP_LIST, 'memberships', self::MODEL_MEMBERSHIP))
                ::setModel(new BaseList('Functions List', self::MODEL_FUNCTION_LIST, 'functions', self::MODEL_FUNCTION))
                ::setModel(new BaseList('Runtimes List', self::MODEL_RUNTIME_LIST, 'runtimes', self::MODEL_RUNTIME))
                ::setModel(new BaseList('Deployments List', self::MODEL_DEPLOYMENT_LIST, 'deployments', self::MODEL_DEPLOYMENT))
                ::setModel(new BaseList('Executions List', self::MODEL_EXECUTION_LIST, 'executions', self::MODEL_EXECUTION))
                ::setModel(new BaseList('Builds List', self::MODEL_BUILD_LIST, 'builds', self::MODEL_BUILD)) // Not used anywhere yet
                ::setModel(new BaseList('Projects List', self::MODEL_PROJECT_LIST, 'projects', self::MODEL_PROJECT, true, false))
                ::setModel(new BaseList('Webhooks List', self::MODEL_WEBHOOK_LIST, 'webhooks', self::MODEL_WEBHOOK, true, false))
                ::setModel(new BaseList('API Keys List', self::MODEL_KEY_LIST, 'keys', self::MODEL_KEY, true, false))
                ::setModel(new BaseList('Providers List', self::MODEL_PROVIDER_LIST, 'platforms', self::MODEL_PROVIDER, true, false))
                ::setModel(new BaseList('Platforms List', self::MODEL_PLATFORM_LIST, 'platforms', self::MODEL_PLATFORM, true, false))
                ::setModel(new BaseList('Domains List', self::MODEL_DOMAIN_LIST, 'domains', self::MODEL_DOMAIN, true, false))
                ::setModel(new BaseList('Countries List', self::MODEL_COUNTRY_LIST, 'countries', self::MODEL_COUNTRY))
                ::setModel(new BaseList('Continents List', self::MODEL_CONTINENT_LIST, 'continents', self::MODEL_CONTINENT))
                ::setModel(new BaseList('Languages List', self::MODEL_LANGUAGE_LIST, 'languages', self::MODEL_LANGUAGE))
                ::setModel(new BaseList('Currencies List', self::MODEL_CURRENCY_LIST, 'currencies', self::MODEL_CURRENCY))
                ::setModel(new BaseList('Phones List', self::MODEL_PHONE_LIST, 'phones', self::MODEL_PHONE))
                ::setModel(new BaseList('Metric List', self::MODEL_METRIC_LIST, 'metrics', self::MODEL_METRIC, true, false))
                ::setModel(new BaseList('Variables List', self::MODEL_VARIABLE_LIST, 'variables', self::MODEL_VARIABLE))
                // Entities
                ::setModel(new Database())
                ::setModel(new Collection())
                ::setModel(new Attribute())
                ::setModel(new AttributeList())
                ::setModel(new AttributeString())
                ::setModel(new AttributeInteger())
                ::setModel(new AttributeFloat())
                ::setModel(new AttributeBoolean())
                ::setModel(new AttributeEmail())
                ::setModel(new AttributeEnum())
                ::setModel(new AttributeIP())
                ::setModel(new AttributeURL())
                ::setModel(new AttributeDatetime())
                ::setModel(new Index())
                ::setModel(new ModelDocument())
                ::setModel(new Log())
                ::setModel(new User())
                ::setModel(new AlgoMd5())
                ::setModel(new AlgoSha())
                ::setModel(new AlgoPhpass())
                ::setModel(new AlgoBcrypt())
                ::setModel(new AlgoScrypt())
                ::setModel(new AlgoScryptModified())
                ::setModel(new AlgoArgon2())
                ::setModel(new Account())
                ::setModel(new Preferences())
                ::setModel(new Session())
                ::setModel(new Token())
                ::setModel(new JWT())
                ::setModel(new Locale())
                ::setModel(new File())
                ::setModel(new Bucket())
                ::setModel(new Team())
                ::setModel(new Membership())
                ::setModel(new Func())
                ::setModel(new Runtime())
                ::setModel(new Deployment())
                ::setModel(new Execution())
                ::setModel(new Build())
                ::setModel(new Project())
                ::setModel(new Webhook())
                ::setModel(new Key())
                ::setModel(new Domain())
                ::setModel(new Provider())
                ::setModel(new Platform())
                ::setModel(new Variable())
                ::setModel(new Country())
                ::setModel(new Continent())
                ::setModel(new Language())
                ::setModel(new Currency())
                ::setModel(new Phone())
                ::setModel(new HealthAntivirus())
                ::setModel(new HealthQueue())
                ::setModel(new HealthStatus())
                ::setModel(new HealthTime())
                ::setModel(new HealthVersion())
                ::setModel(new Metric())
                ::setModel(new UsageDatabases())
                ::setModel(new UsageDatabase())
                ::setModel(new UsageCollection())
                ::setModel(new UsageUsers())
                ::setModel(new UsageStorage())
                ::setModel(new UsageBuckets())
                ::setModel(new UsageFunctions())
                ::setModel(new UsageFunction())
                ::setModel(new UsageProject())
                // Verification
                // Recovery
                // Tests (keep last)
                ::setModel(new Mock());
        }

        parent::__construct($response);
    }

    /**
     * HTTP content types
     */
    public const CONTENT_TYPE_YAML = 'application/x-yaml';
    public const CONTENT_TYPE_NULL = 'null';

    /**
     * List of defined output objects
     */
    protected static array $models = [];

    /**
     * Set Model Object
     */
    public static function setModel(Model $instance): static
    {
        self::$models[$instance->getType()] = $instance;

        return static ;
    }

    /**
     * Get Model Object
     *
     * @param string $key
     * @return Model
     * @throws Exception
     */
    public static function getModel(string $key): Model
    {
        return self::$models[$key] ?? throw new Exception('Undefined model: ' . $key);
    }

    /**
     * Get Models List
     *
     * @return array<string,Model>
     */
    public static function getModels(): array
    {
        return self::$models;
    }

    /**
     * Validate response objects and outputs
     *  the response according to given format type
     *
     * @param Document $document
     * @param string $model
     *
     * return void
     * @throws Exception
     */
    public function dynamic(Document $document, string $model): void
    {
        $output = $this->output($document, $model);

        // If filter is set, parse the output
        if (self::hasFilter()) {
            $output = self::getFilter()->parse($output, $model);
        }

        switch ($this->getContentType()) {
            case self::CONTENT_TYPE_JSON:
                $this->json(!empty($output) ? $output : new \stdClass());
                break;

            case self::CONTENT_TYPE_YAML:
                $this->yaml(!empty($output) ? $output : new \stdClass());
                break;

            case self::CONTENT_TYPE_NULL:
                break;

            default:
                if ($model === self::MODEL_NONE) {
                    $this->noContent();
                } else {
                    $this->json(!empty($output) ? $output : new \stdClass());
                }
                break;
        }
    }

    /**
     * Generate valid response object from document data
     *
     * @param Document $document
     * @param string $model
     *
     * return array
     * @return array
     * @throws Exception
     */
    public function output(Document $document, string $model): array
    {
        $data = $document;
        $model = $this->getModel($model);
        $output = [];

        $document = $model->filter($document);

        if ($model->isAny()) {
            $this->payload = $document->getArrayCopy();

            return $this->payload;
        }

        foreach ($model->getRules() as $key => $rule) {
            if (!$document->isSet($key) && $rule->isRequired()) { // do not set attribute in response if not required
                $document->setAttribute($key, $rule->getDefault());
            }

            if ($rule->isArray()) {
                if (!is_array($data[$key])) {
                    throw new Exception($key . ' must be an array of type ' . $rule['type']);
                }

                foreach ($data[$key] as $index => $item) {
                    if ($item instanceof Document) {
                        if (\is_array($rule->getType())) {
                            foreach ($rule->getType() as $type) {
                                $condition = false;
                                foreach ($this->getModel($type)->conditions as $attribute => $val) {
                                    $condition = $item->getAttribute($attribute) === $val;
                                    if (!$condition) {
                                        break;
                                    }
                                }
                                if ($condition) {
                                    $ruleType = $type;
                                    break;
                                }
                            }
                        } else {
                            $ruleType = $rule->getType();
                        }

                        if (!array_key_exists($ruleType, $this->models)) {
                            throw new Exception('Missing model for rule: ' . $ruleType);
                        }

                        $data[$key][$index] = $this->output($item, $ruleType);
                    }
                }
            } else {
                if ($data[$key] instanceof Document) {
                    $data[$key] = $this->output($data[$key], $rule['type']);
                }
            }

            $output[$key] = $data[$key];
        }

        $this->payload = $output;

        return $this->payload;
    }

    /**
     * Output response
     *
     * Generate HTTP response output including the response header (+cookies) and body and prints them.
     *
     * @param string $body
     *
     * @return void
     */
    public function file(string $body = ''): void
    {
        $this->payload = [
            'payload' => $body
        ];

        $this->send($body);
    }

    /**
     * YAML
     *
     * This helper is for sending YAML HTTP response.
     * It sets relevant content type header ('application/x-yaml') and convert a PHP array ($data) to valid YAML using native yaml_parse
     *
     * @see https://en.wikipedia.org/wiki/YAML
     *
     * @param array $data
     *
     * @return void
     * @throws Exception
     */
    public function yaml(array $data): void
    {
        if (!extension_loaded('yaml')) {
            throw new Exception('Missing yaml extension. Learn more at: https://www.php.net/manual/en/book.yaml.php');
        }

        $this
            ->setContentType(Response::CONTENT_TYPE_YAML)
            ->send(yaml_emit($data, YAML_UTF8_ENCODING));
    }

    /**
     * @return array
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Function to set a response filter
     *
     * @param $filter the response filter to set
     *
     * @return void
     */
    public static function setFilter(?Filter $filter)
    {
        self::$filter = $filter;
    }

    /**
     * Return the currently set filter
     *
     * @return Filter
     */
    public static function getFilter(): ?Filter
    {
        return self::$filter;
    }

    /**
     * Check if a filter has been set
     *
     * @return bool
     */
    public static function hasFilter(): bool
    {
        return self::$filter !== null;
    }
}
