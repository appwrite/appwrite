<?php

namespace Appwrite\Utopia;

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
use Appwrite\Utopia\Response\Model\Any;
use Appwrite\Utopia\Response\Model\Attribute;
use Appwrite\Utopia\Response\Model\AttributeBoolean;
use Appwrite\Utopia\Response\Model\AttributeDatetime;
use Appwrite\Utopia\Response\Model\AttributeEmail;
use Appwrite\Utopia\Response\Model\AttributeEnum;
use Appwrite\Utopia\Response\Model\AttributeFloat;
use Appwrite\Utopia\Response\Model\AttributeInteger;
use Appwrite\Utopia\Response\Model\AttributeIP;
use Appwrite\Utopia\Response\Model\AttributeList;
use Appwrite\Utopia\Response\Model\AttributeRelationship;
use Appwrite\Utopia\Response\Model\AttributeString;
use Appwrite\Utopia\Response\Model\AttributeURL;
use Appwrite\Utopia\Response\Model\AuthProvider;
use Appwrite\Utopia\Response\Model\BaseList;
use Appwrite\Utopia\Response\Model\Branch;
use Appwrite\Utopia\Response\Model\Bucket;
use Appwrite\Utopia\Response\Model\Build;
use Appwrite\Utopia\Response\Model\Collection;
use Appwrite\Utopia\Response\Model\ConsoleVariables;
use Appwrite\Utopia\Response\Model\Continent;
use Appwrite\Utopia\Response\Model\Country;
use Appwrite\Utopia\Response\Model\Currency;
use Appwrite\Utopia\Response\Model\Database;
use Appwrite\Utopia\Response\Model\Deployment;
use Appwrite\Utopia\Response\Model\Detection;
use Appwrite\Utopia\Response\Model\Document as ModelDocument;
use Appwrite\Utopia\Response\Model\Error;
use Appwrite\Utopia\Response\Model\ErrorDev;
use Appwrite\Utopia\Response\Model\Execution;
use Appwrite\Utopia\Response\Model\File;
use Appwrite\Utopia\Response\Model\Func;
use Appwrite\Utopia\Response\Model\Headers;
use Appwrite\Utopia\Response\Model\HealthAntivirus;
use Appwrite\Utopia\Response\Model\HealthCertificate;
use Appwrite\Utopia\Response\Model\HealthQueue;
use Appwrite\Utopia\Response\Model\HealthStatus;
use Appwrite\Utopia\Response\Model\HealthTime;
use Appwrite\Utopia\Response\Model\HealthVersion;
use Appwrite\Utopia\Response\Model\Identity;
use Appwrite\Utopia\Response\Model\Index;
use Appwrite\Utopia\Response\Model\Installation;
use Appwrite\Utopia\Response\Model\JWT;
use Appwrite\Utopia\Response\Model\Key;
use Appwrite\Utopia\Response\Model\Language;
use Appwrite\Utopia\Response\Model\Locale;
use Appwrite\Utopia\Response\Model\LocaleCode;
use Appwrite\Utopia\Response\Model\Log;
use Appwrite\Utopia\Response\Model\Membership;
use Appwrite\Utopia\Response\Model\Message;
use Appwrite\Utopia\Response\Model\Metric;
use Appwrite\Utopia\Response\Model\MetricBreakdown;
use Appwrite\Utopia\Response\Model\MFAChallenge;
use Appwrite\Utopia\Response\Model\MFAFactors;
use Appwrite\Utopia\Response\Model\MFARecoveryCodes;
use Appwrite\Utopia\Response\Model\MFAType;
use Appwrite\Utopia\Response\Model\Migration;
use Appwrite\Utopia\Response\Model\MigrationFirebaseProject;
use Appwrite\Utopia\Response\Model\MigrationReport;
use Appwrite\Utopia\Response\Model\Mock;
use Appwrite\Utopia\Response\Model\None;
use Appwrite\Utopia\Response\Model\Phone;
use Appwrite\Utopia\Response\Model\Platform;
use Appwrite\Utopia\Response\Model\Preferences;
use Appwrite\Utopia\Response\Model\Project;
use Appwrite\Utopia\Response\Model\Provider;
use Appwrite\Utopia\Response\Model\ProviderRepository;
use Appwrite\Utopia\Response\Model\Rule;
use Appwrite\Utopia\Response\Model\Runtime;
use Appwrite\Utopia\Response\Model\Session;
use Appwrite\Utopia\Response\Model\Subscriber;
use Appwrite\Utopia\Response\Model\Target;
use Appwrite\Utopia\Response\Model\Team;
use Appwrite\Utopia\Response\Model\TemplateEmail;
use Appwrite\Utopia\Response\Model\TemplateSMS;
use Appwrite\Utopia\Response\Model\Token;
use Appwrite\Utopia\Response\Model\Topic;
use Appwrite\Utopia\Response\Model\UsageBuckets;
use Appwrite\Utopia\Response\Model\UsageCollection;
use Appwrite\Utopia\Response\Model\UsageDatabase;
use Appwrite\Utopia\Response\Model\UsageDatabases;
use Appwrite\Utopia\Response\Model\UsageFunction;
use Appwrite\Utopia\Response\Model\UsageFunctions;
use Appwrite\Utopia\Response\Model\UsageProject;
use Appwrite\Utopia\Response\Model\UsageStorage;
use Appwrite\Utopia\Response\Model\UsageUsers;
use Appwrite\Utopia\Response\Model\User;
use Appwrite\Utopia\Response\Model\Variable;
use Appwrite\Utopia\Response\Model\Webhook;
use Exception;
// Keep last
use Utopia\Database\Document;
use Utopia\Http\Adapter\Swoole\Response as HttpResponse;

/**
 * @method int getStatusCode()
 * @method Response setStatusCode(int $code = 200)
 */
class Response extends HttpResponse
{
    // General
    public const MODEL_NONE = 'none';
    public const MODEL_ANY = 'any';
    public const MODEL_LOG = 'log';
    public const MODEL_LOG_LIST = 'logList';
    public const MODEL_ERROR = 'error';
    public const MODEL_METRIC = 'metric';
    public const MODEL_METRIC_LIST = 'metricList';
    public const MODEL_METRIC_BREAKDOWN = 'metricBreakdown';
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
    public const MODEL_ATTRIBUTE_RELATIONSHIP = 'attributeRelationship';

    // Users
    public const MODEL_ACCOUNT = 'account';
    public const MODEL_USER = 'user';
    public const MODEL_USER_LIST = 'userList';
    public const MODEL_SESSION = 'session';
    public const MODEL_SESSION_LIST = 'sessionList';
    public const MODEL_IDENTITY = 'identity';
    public const MODEL_IDENTITY_LIST = 'identityList';
    public const MODEL_TOKEN = 'token';
    public const MODEL_JWT = 'jwt';
    public const MODEL_PREFERENCES = 'preferences';

    // MFA
    public const MODEL_MFA_TYPE = 'mfaType';
    public const MODEL_MFA_FACTORS = 'mfaFactors';
    public const MODEL_MFA_OTP = 'mfaTotp';
    public const MODEL_MFA_CHALLENGE = 'mfaChallenge';
    public const MODEL_MFA_RECOVERY_CODES = 'mfaRecoveryCodes';

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
    public const MODEL_LOCALE_CODE = 'localeCode';
    public const MODEL_LOCALE_CODE_LIST = 'localeCodeList';
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

    // Messaging
    public const MODEL_PROVIDER = 'provider';
    public const MODEL_PROVIDER_LIST = 'providerList';
    public const MODEL_MESSAGE = 'message';
    public const MODEL_MESSAGE_LIST = 'messageList';
    public const MODEL_TOPIC = 'topic';
    public const MODEL_TOPIC_LIST = 'topicList';
    public const MODEL_SUBSCRIBER = 'subscriber';
    public const MODEL_SUBSCRIBER_LIST = 'subscriberList';
    public const MODEL_TARGET = 'target';
    public const MODEL_TARGET_LIST = 'targetList';

    // Teams
    public const MODEL_TEAM = 'team';
    public const MODEL_TEAM_LIST = 'teamList';
    public const MODEL_MEMBERSHIP = 'membership';
    public const MODEL_MEMBERSHIP_LIST = 'membershipList';

    // VCS
    public const MODEL_INSTALLATION = 'installation';
    public const MODEL_INSTALLATION_LIST = 'installationList';
    public const MODEL_PROVIDER_REPOSITORY = 'providerRepository';
    public const MODEL_PROVIDER_REPOSITORY_LIST = 'providerRepositoryList';
    public const MODEL_BRANCH = 'branch';
    public const MODEL_BRANCH_LIST = 'branchList';
    public const MODEL_DETECTION = 'detection';

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
    public const MODEL_HEADERS = 'headers';

    // Proxy
    public const MODEL_PROXY_RULE = 'proxyRule';
    public const MODEL_PROXY_RULE_LIST = 'proxyRuleList';

    // Migrations
    public const MODEL_MIGRATION = 'migration';
    public const MODEL_MIGRATION_LIST = 'migrationList';
    public const MODEL_MIGRATION_REPORT = 'migrationReport';
    public const MODEL_MIGRATION_FIREBASE_PROJECT = 'firebaseProject';
    public const MODEL_MIGRATION_FIREBASE_PROJECT_LIST = 'firebaseProjectList';

    // Project
    public const MODEL_PROJECT = 'project';
    public const MODEL_PROJECT_LIST = 'projectList';
    public const MODEL_WEBHOOK = 'webhook';
    public const MODEL_WEBHOOK_LIST = 'webhookList';
    public const MODEL_KEY = 'key';
    public const MODEL_KEY_LIST = 'keyList';
    public const MODEL_AUTH_PROVIDER = 'authProvider';
    public const MODEL_AUTH_PROVIDER_LIST = 'authProviderList';
    public const MODEL_PLATFORM = 'platform';
    public const MODEL_PLATFORM_LIST = 'platformList';
    public const MODEL_VARIABLE = 'variable';
    public const MODEL_VARIABLE_LIST = 'variableList';
    public const MODEL_VCS = 'vcs';
    public const MODEL_SMS_TEMPLATE = 'smsTemplate';
    public const MODEL_EMAIL_TEMPLATE = 'emailTemplate';

    // Health
    public const MODEL_HEALTH_STATUS = 'healthStatus';
    public const MODEL_HEALTH_VERSION = 'healthVersion';
    public const MODEL_HEALTH_QUEUE = 'healthQueue';
    public const MODEL_HEALTH_TIME = 'healthTime';
    public const MODEL_HEALTH_ANTIVIRUS = 'healthAntivirus';
    public const MODEL_HEALTH_CERTIFICATE = 'healthCertificate';
    public const MODEL_HEALTH_STATUS_LIST = 'healthStatusList';

    // Console
    public const MODEL_CONSOLE_VARIABLES = 'consoleVariables';

    // Deprecated
    public const MODEL_PERMISSIONS = 'permissions';
    public const MODEL_RULE = 'rule';
    public const MODEL_TASK = 'task';
    public const MODEL_DOMAIN = 'domain';
    public const MODEL_DOMAIN_LIST = 'domainList';

    // Tests (keep last)
    public const MODEL_MOCK = 'mock';

    /**
     * @var array<Filter>
     */
    protected array $filters = [];

    /**
     * @var array
     */
    protected array $payload = [];

    /**
     * Response constructor.
     *
     * @param float $time
     */
    public function __construct(HttpResponse $response)
    {
        $this
            // General
            ->setModel(new None())
            ->setModel(new Any())
            ->setModel(new Error())
            ->setModel(new ErrorDev())
            // Lists
            ->setModel(new BaseList('Documents List', self::MODEL_DOCUMENT_LIST, 'documents', self::MODEL_DOCUMENT))
            ->setModel(new BaseList('Collections List', self::MODEL_COLLECTION_LIST, 'collections', self::MODEL_COLLECTION))
            ->setModel(new BaseList('Databases List', self::MODEL_DATABASE_LIST, 'databases', self::MODEL_DATABASE))
            ->setModel(new BaseList('Indexes List', self::MODEL_INDEX_LIST, 'indexes', self::MODEL_INDEX))
            ->setModel(new BaseList('Users List', self::MODEL_USER_LIST, 'users', self::MODEL_USER))
            ->setModel(new BaseList('Sessions List', self::MODEL_SESSION_LIST, 'sessions', self::MODEL_SESSION))
            ->setModel(new BaseList('Identities List', self::MODEL_IDENTITY_LIST, 'identities', self::MODEL_IDENTITY))
            ->setModel(new BaseList('Logs List', self::MODEL_LOG_LIST, 'logs', self::MODEL_LOG))
            ->setModel(new BaseList('Files List', self::MODEL_FILE_LIST, 'files', self::MODEL_FILE))
            ->setModel(new BaseList('Buckets List', self::MODEL_BUCKET_LIST, 'buckets', self::MODEL_BUCKET))
            ->setModel(new BaseList('Teams List', self::MODEL_TEAM_LIST, 'teams', self::MODEL_TEAM))
            ->setModel(new BaseList('Memberships List', self::MODEL_MEMBERSHIP_LIST, 'memberships', self::MODEL_MEMBERSHIP))
            ->setModel(new BaseList('Functions List', self::MODEL_FUNCTION_LIST, 'functions', self::MODEL_FUNCTION))
            ->setModel(new BaseList('Installations List', self::MODEL_INSTALLATION_LIST, 'installations', self::MODEL_INSTALLATION))
            ->setModel(new BaseList('Provider Repositories List', self::MODEL_PROVIDER_REPOSITORY_LIST, 'providerRepositories', self::MODEL_PROVIDER_REPOSITORY))
            ->setModel(new BaseList('Branches List', self::MODEL_BRANCH_LIST, 'branches', self::MODEL_BRANCH))
            ->setModel(new BaseList('Runtimes List', self::MODEL_RUNTIME_LIST, 'runtimes', self::MODEL_RUNTIME))
            ->setModel(new BaseList('Deployments List', self::MODEL_DEPLOYMENT_LIST, 'deployments', self::MODEL_DEPLOYMENT))
            ->setModel(new BaseList('Executions List', self::MODEL_EXECUTION_LIST, 'executions', self::MODEL_EXECUTION))
            ->setModel(new BaseList('Builds List', self::MODEL_BUILD_LIST, 'builds', self::MODEL_BUILD)) // Not used anywhere yet
            ->setModel(new BaseList('Projects List', self::MODEL_PROJECT_LIST, 'projects', self::MODEL_PROJECT, true, false))
            ->setModel(new BaseList('Webhooks List', self::MODEL_WEBHOOK_LIST, 'webhooks', self::MODEL_WEBHOOK, true, false))
            ->setModel(new BaseList('API Keys List', self::MODEL_KEY_LIST, 'keys', self::MODEL_KEY, true, false))
            ->setModel(new BaseList('Auth Providers List', self::MODEL_AUTH_PROVIDER_LIST, 'platforms', self::MODEL_AUTH_PROVIDER, true, false))
            ->setModel(new BaseList('Platforms List', self::MODEL_PLATFORM_LIST, 'platforms', self::MODEL_PLATFORM, true, false))
            ->setModel(new BaseList('Countries List', self::MODEL_COUNTRY_LIST, 'countries', self::MODEL_COUNTRY))
            ->setModel(new BaseList('Continents List', self::MODEL_CONTINENT_LIST, 'continents', self::MODEL_CONTINENT))
            ->setModel(new BaseList('Languages List', self::MODEL_LANGUAGE_LIST, 'languages', self::MODEL_LANGUAGE))
            ->setModel(new BaseList('Currencies List', self::MODEL_CURRENCY_LIST, 'currencies', self::MODEL_CURRENCY))
            ->setModel(new BaseList('Phones List', self::MODEL_PHONE_LIST, 'phones', self::MODEL_PHONE))
            ->setModel(new BaseList('Metric List', self::MODEL_METRIC_LIST, 'metrics', self::MODEL_METRIC, true, false))
            ->setModel(new BaseList('Variables List', self::MODEL_VARIABLE_LIST, 'variables', self::MODEL_VARIABLE))
            ->setModel(new BaseList('Status List', self::MODEL_HEALTH_STATUS_LIST, 'statuses', self::MODEL_HEALTH_STATUS))
            ->setModel(new BaseList('Rule List', self::MODEL_PROXY_RULE_LIST, 'rules', self::MODEL_PROXY_RULE))
            ->setModel(new BaseList('Locale codes list', self::MODEL_LOCALE_CODE_LIST, 'localeCodes', self::MODEL_LOCALE_CODE))
            ->setModel(new BaseList('Provider list', self::MODEL_PROVIDER_LIST, 'providers', self::MODEL_PROVIDER))
            ->setModel(new BaseList('Message list', self::MODEL_MESSAGE_LIST, 'messages', self::MODEL_MESSAGE))
            ->setModel(new BaseList('Topic list', self::MODEL_TOPIC_LIST, 'topics', self::MODEL_TOPIC))
            ->setModel(new BaseList('Subscriber list', self::MODEL_SUBSCRIBER_LIST, 'subscribers', self::MODEL_SUBSCRIBER))
            ->setModel(new BaseList('Target list', self::MODEL_TARGET_LIST, 'targets', self::MODEL_TARGET))
            ->setModel(new BaseList('Migrations List', self::MODEL_MIGRATION_LIST, 'migrations', self::MODEL_MIGRATION))
            ->setModel(new BaseList('Migrations Firebase Projects List', self::MODEL_MIGRATION_FIREBASE_PROJECT_LIST, 'projects', self::MODEL_MIGRATION_FIREBASE_PROJECT))
            // Entities
            ->setModel(new Database())
            ->setModel(new Collection())
            ->setModel(new Attribute())
            ->setModel(new AttributeList())
            ->setModel(new AttributeString())
            ->setModel(new AttributeInteger())
            ->setModel(new AttributeFloat())
            ->setModel(new AttributeBoolean())
            ->setModel(new AttributeEmail())
            ->setModel(new AttributeEnum())
            ->setModel(new AttributeIP())
            ->setModel(new AttributeURL())
            ->setModel(new AttributeDatetime())
            ->setModel(new AttributeRelationship())
            ->setModel(new Index())
            ->setModel(new ModelDocument())
            ->setModel(new Log())
            ->setModel(new User())
            ->setModel(new AlgoMd5())
            ->setModel(new AlgoSha())
            ->setModel(new AlgoPhpass())
            ->setModel(new AlgoBcrypt())
            ->setModel(new AlgoScrypt())
            ->setModel(new AlgoScryptModified())
            ->setModel(new AlgoArgon2())
            ->setModel(new Account())
            ->setModel(new Preferences())
            ->setModel(new Session())
            ->setModel(new Identity())
            ->setModel(new Token())
            ->setModel(new JWT())
            ->setModel(new Locale())
            ->setModel(new LocaleCode())
            ->setModel(new File())
            ->setModel(new Bucket())
            ->setModel(new Team())
            ->setModel(new Membership())
            ->setModel(new Func())
            ->setModel(new Installation())
            ->setModel(new ProviderRepository())
            ->setModel(new Detection())
            ->setModel(new Branch())
            ->setModel(new Runtime())
            ->setModel(new Deployment())
            ->setModel(new Execution())
            ->setModel(new Build())
            ->setModel(new Project())
            ->setModel(new Webhook())
            ->setModel(new Key())
            ->setModel(new AuthProvider())
            ->setModel(new Platform())
            ->setModel(new Variable())
            ->setModel(new Country())
            ->setModel(new Continent())
            ->setModel(new Language())
            ->setModel(new Currency())
            ->setModel(new Phone())
            ->setModel(new HealthAntivirus())
            ->setModel(new HealthQueue())
            ->setModel(new HealthStatus())
            ->setModel(new HealthCertificate())
            ->setModel(new HealthTime())
            ->setModel(new HealthVersion())
            ->setModel(new Metric())
            ->setModel(new MetricBreakdown())
            ->setModel(new UsageDatabases())
            ->setModel(new UsageDatabase())
            ->setModel(new UsageCollection())
            ->setModel(new UsageUsers())
            ->setModel(new UsageStorage())
            ->setModel(new UsageBuckets())
            ->setModel(new UsageFunctions())
            ->setModel(new UsageFunction())
            ->setModel(new UsageProject())
            ->setModel(new Headers())
            ->setModel(new Rule())
            ->setModel(new TemplateSMS())
            ->setModel(new TemplateEmail())
            ->setModel(new ConsoleVariables())
            ->setModel(new MFAChallenge())
            ->setModel(new MFARecoveryCodes())
            ->setModel(new MFAType())
            ->setModel(new MFAFactors())
            ->setModel(new Provider())
            ->setModel(new Message())
            ->setModel(new Topic())
            ->setModel(new Subscriber())
            ->setModel(new Target())
            ->setModel(new Migration())
            ->setModel(new MigrationReport())
            ->setModel(new MigrationFirebaseProject())
            // Tests (keep last)
            ->setModel(new Mock());

            parent::__construct($response->swoole);
    }

    /**
     * HTTP content types
     */
    public const CONTENT_TYPE_YAML = 'application/x-yaml';
    public const CONTENT_TYPE_NULL = 'null';

    /**
     * List of defined output objects
     */
    protected $models = [];

    /**
     * Set Model Object
     *
     * @return self
     */
    public function setModel(Model $instance)
    {
        $this->models[$instance->getType()] = $instance;

        return $this;
    }

    /**
     * Get Model Object
     *
     * @param string $key
     * @return Model
     * @throws Exception
     */
    public function getModel(string $key): Model
    {
        if (!isset($this->models[$key])) {
            throw new Exception('Undefined model: ' . $key);
        }

        return $this->models[$key];
    }

    /**
     * Get Models List
     *
     * @return Model[]
     */
    public function getModels(): array
    {
        return $this->models;
    }

    public function applyFilters(array $data, string $model): array
    {
        foreach ($this->filters as $filter) {
            $data = $filter->parse($data, $model);
        }

        return $data;
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
        $output = $this->output(clone $document, $model);
        $output = $this->applyFilters($output, $model);

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
        $data       = clone $document;
        $model      = $this->getModel($model);
        $output     = [];

        $data = $model->filter($data);

        if ($model->isAny()) {
            $this->payload = $data->getArrayCopy();

            return $this->payload;
        }

        foreach ($model->getRules() as $key => $rule) {
            if (!$data->isSet($key) && $rule['required']) { // do not set attribute in response if not required
                if (\array_key_exists('default', $rule)) {
                    $data->setAttribute($key, $rule['default']);
                } else {
                    throw new Exception('Model ' . $model->getName() . ' is missing response key: ' . $key);
                }
            }

            if ($rule['array']) {
                if (!is_array($data[$key])) {
                    throw new Exception($key . ' must be an array of type ' . $rule['type']);
                }

                foreach ($data[$key] as $index => $item) {
                    if ($item instanceof Document) {
                        if (\is_array($rule['type'])) {
                            foreach ($rule['type'] as $type) {
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
                            $ruleType = $rule['type'];
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
            ->send(\yaml_emit($data, YAML_UTF8_ENCODING));
    }

    /**
     * @return array
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Function to add a response filter, the order of filters are first in - first out.
     *
     * @param $filter the response filter to set
     *
     * @return void
     */
    public function addFilter(Filter $filter): void
    {
        $this->filters[] = $filter;
    }

    /**
     * Return the currently set filter
     *
     * @return Filter
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * Reset filters
     *
     * @return void
     */
    public function resetFilters(): void
    {
        $this->filters = [];
    }

    /**
     * Check if a filter has been set
     *
     * @return bool
     */
    public function hasFilters(): bool
    {
        return !empty($this->filters);
    }

    /**
     * Set Header
     *
     * @param  string  $key
     * @param  string  $value
     * @return void
     */
    public function setHeader(string $key, string $value): void
    {
        $this->sendHeader($key, $value);
    }
}
