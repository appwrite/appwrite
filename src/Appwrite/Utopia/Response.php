<?php

namespace Appwrite\Utopia;

use Appwrite\Utopia\Fetch\BodyMultipart;
use Appwrite\Utopia\Response\Filter;
use Exception;
use JsonException;
use Swoole\Http\Response as SwooleHTTPResponse;
// Keep last
use Utopia\Database\Document;
use Utopia\Swoole\Response as SwooleResponse;
// Keep last

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
    public const MODEL_VCS_CONTENT = 'vcsContent';
    public const MODEL_VCS_CONTENT_LIST = 'vcsContentList';

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
    public const MODEL_SPECIFICATION = 'specification';
    public const MODEL_SPECIFICATION_LIST = 'specificationList';
    public const MODEL_TEMPLATE_FUNCTION = 'templateFunction';
    public const MODEL_TEMPLATE_FUNCTION_LIST = 'templateFunctionList';
    public const MODEL_TEMPLATE_RUNTIME = 'templateRuntime';
    public const MODEL_TEMPLATE_VARIABLE = 'templateVariable';

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
    public const MODEL_MOCK_NUMBER = 'mockNumber';
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
        parent::__construct($response->swoole);
    }

    /**
     * HTTP content types
     */
    public const CONTENT_TYPE_YAML = 'application/x-yaml';
    public const CONTENT_TYPE_NULL = 'null';
    public const CONTENT_TYPE_MULTIPART = 'multipart/form-data';

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
                try {
                    $this->json(!empty($output) ? $output : new \stdClass());
                } catch (JsonException $e) {
                    throw new Exception('Failed to parse response: ' . $e->getMessage(), 400);
                }
                break;

            case self::CONTENT_TYPE_YAML:
                $this->yaml(!empty($output) ? $output : new \stdClass());
                break;

            case self::CONTENT_TYPE_NULL:
                break;

            case self::CONTENT_TYPE_MULTIPART:
                $this->multipart(!empty($output) ? $output : new \stdClass());
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
        $model      = Response\Models::getModel($model);
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
                                foreach (Response\Models::getModel($type)->conditions as $attribute => $val) {
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

                        if (!array_key_exists($ruleType, Response\Models::getModels())) {
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
     * Multipart
     *
     * This helper is for sending multipart/form-data HTTP response.
     * It sets relevant content type header ('multipart/form-data') and convert a PHP array ($data) to valid Multipart using BodyMultipart
     *
     * @param array $data
     *
     * @return void
     */
    public function multipart(array $data): void
    {
        $multipart = new BodyMultipart();
        foreach ($data as $key => $value) {
            $multipart->setPart($key, $value);
        }

        $this
            ->setContentType($multipart->exportHeader())
            ->send($multipart->exportBody());
    }

    /**
     * JSON
     *
     * This helper is for sending JSON HTTP response.
     * It sets relevant content type header ('application/json') and convert a PHP array ($data) to valid JSON using native json_encode
     *
     * @see http://en.wikipedia.org/wiki/JSON
     *
     * @param  mixed  $data
     * @return void
     */
    public function json($data): void
    {
        if (!is_array($data) && !$data instanceof \stdClass) {
            throw new \Exception('Response body is not a valid JSON object.');
        }

        $this
            ->setContentType(Response::CONTENT_TYPE_JSON, self::CHARSET_UTF8)
            ->send(\json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
