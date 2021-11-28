<?php

namespace Appwrite\Utopia;

use Exception;
use Utopia\Swoole\Response as SwooleResponse;
use Swoole\Http\Response as SwooleHTTPResponse;
use Utopia\Database\Document;
use Appwrite\Utopia\Response\Filter;
use Appwrite\Utopia\Response\Model;
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
use Appwrite\Utopia\Response\Model\BaseList;
use Appwrite\Utopia\Response\Model\Collection;
use Appwrite\Utopia\Response\Model\Continent;
use Appwrite\Utopia\Response\Model\Country;
use Appwrite\Utopia\Response\Model\Currency;
use Appwrite\Utopia\Response\Model\Document as ModelDocument;
use Appwrite\Utopia\Response\Model\Domain;
use Appwrite\Utopia\Response\Model\Error;
use Appwrite\Utopia\Response\Model\ErrorDev;
use Appwrite\Utopia\Response\Model\Execution;
use Appwrite\Utopia\Response\Model\File;
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
use Appwrite\Utopia\Response\Model\Tag;
use Appwrite\Utopia\Response\Model\Token;
use Appwrite\Utopia\Response\Model\Webhook;
use Appwrite\Utopia\Response\Model\Preferences;
use Appwrite\Utopia\Response\Model\Mock; // Keep last
use Appwrite\Utopia\Response\Model\UsageBuckets;
use Appwrite\Utopia\Response\Model\UsageCollection;
use Appwrite\Utopia\Response\Model\UsageDatabase;
use Appwrite\Utopia\Response\Model\UsageFunctions;
use Appwrite\Utopia\Response\Model\UsageProject;
use Appwrite\Utopia\Response\Model\UsageStorage;
use Appwrite\Utopia\Response\Model\UsageUsers;

/**
 * @method Response setStatusCode(int $code = 200)
 */
class Response extends SwooleResponse
{
    // General
    const MODEL_NONE = 'none';
    const MODEL_ANY = 'any';
    const MODEL_LOG = 'log';
    const MODEL_LOG_LIST = 'logList';
    const MODEL_ERROR = 'error';
    const MODEL_METRIC = 'metric';
    const MODEL_METRIC_LIST = 'metricList';
    const MODEL_ERROR_DEV = 'errorDev';
    const MODEL_BASE_LIST = 'baseList';
    const MODEL_USAGE_DATABASE = 'usageDatabase';
    const MODEL_USAGE_COLLECTION = 'usageCollection';
    const MODEL_USAGE_USERS = 'usageUsers';
    const MODEL_USAGE_BUCKETS = 'usageBuckets';
    const MODEL_USAGE_STORAGE = 'usageStorage';
    const MODEL_USAGE_FUNCTIONS = 'usageFunctions';
    const MODEL_USAGE_PROJECT = 'usageProject';
    
    // Database
    const MODEL_COLLECTION = 'collection';
    const MODEL_COLLECTION_LIST = 'collectionList';
    const MODEL_INDEX = 'index';
    const MODEL_INDEX_LIST = 'indexList';
    const MODEL_DOCUMENT = 'document';
    const MODEL_DOCUMENT_LIST = 'documentList';

    // Database Attributes
    const MODEL_ATTRIBUTE = 'attribute';
    const MODEL_ATTRIBUTE_LIST = 'attributeList';
    const MODEL_ATTRIBUTE_STRING = 'attributeString';
    const MODEL_ATTRIBUTE_INTEGER = 'attributeInteger';
    const MODEL_ATTRIBUTE_FLOAT = 'attributeFloat';
    const MODEL_ATTRIBUTE_BOOLEAN = 'attributeBoolean';
    const MODEL_ATTRIBUTE_EMAIL = 'attributeEmail';
    const MODEL_ATTRIBUTE_ENUM = 'attributeEnum';
    const MODEL_ATTRIBUTE_IP = 'attributeIp';
    const MODEL_ATTRIBUTE_URL= 'attributeUrl';

    // Users
    const MODEL_USER = 'user';
    const MODEL_USER_LIST = 'userList';
    const MODEL_SESSION = 'session';
    const MODEL_SESSION_LIST = 'sessionList';
    const MODEL_TOKEN = 'token';
    const MODEL_JWT = 'jwt';
    const MODEL_PREFERENCES = 'preferences';
    
    // Storage
    const MODEL_FILE = 'file';
    const MODEL_FILE_LIST = 'fileList';
    const MODEL_BUCKET = 'bucket'; // - Missing

    // Locale
    const MODEL_LOCALE = 'locale';
    const MODEL_COUNTRY = 'country';
    const MODEL_COUNTRY_LIST = 'countryList';
    const MODEL_CONTINENT = 'continent';
    const MODEL_CONTINENT_LIST = 'continentList';
    const MODEL_CURRENCY = 'currency';
    const MODEL_CURRENCY_LIST = 'currencyList';
    const MODEL_LANGUAGE = 'language';
    const MODEL_LANGUAGE_LIST = 'languageList';
    const MODEL_PHONE = 'phone';
    const MODEL_PHONE_LIST = 'phoneList';

    // Teams
    const MODEL_TEAM = 'team';
    const MODEL_TEAM_LIST = 'teamList';
    const MODEL_MEMBERSHIP = 'membership';
    const MODEL_MEMBERSHIP_LIST = 'membershipList';

    // Functions
    const MODEL_FUNCTION = 'function';
    const MODEL_FUNCTION_LIST = 'functionList';
    const MODEL_TAG = 'tag';
    const MODEL_TAG_LIST = 'tagList';
    const MODEL_EXECUTION = 'execution';
    const MODEL_EXECUTION_LIST = 'executionList';
    
    // Project
    const MODEL_PROJECT = 'project';
    const MODEL_PROJECT_LIST = 'projectList';
    const MODEL_WEBHOOK = 'webhook';
    const MODEL_WEBHOOK_LIST = 'webhookList';
    const MODEL_KEY = 'key';
    const MODEL_KEY_LIST = 'keyList';
    const MODEL_PLATFORM = 'platform';
    const MODEL_PLATFORM_LIST = 'platformList';
    const MODEL_DOMAIN = 'domain';
    const MODEL_DOMAIN_LIST = 'domainList';
    
    // Deprecated
    const MODEL_PERMISSIONS = 'permissions';
    const MODEL_RULE = 'rule';
    const MODEL_TASK = 'task';

    // Tests (keep last)
    const MODEL_MOCK = 'mock';

    /**
     * @var Filter
     */
    private static $filter = null;

    /**
     * @var array
     */
    protected $payload = [];

    /**
     * Response constructor.
     *
     * @param float $time
     */
    public function __construct(SwooleHTTPResponse $response)
    {
        $this
            // General
            ->setModel(new None())
            ->setModel(new Any())
            ->setModel(new Error())
            ->setModel(new ErrorDev())
            // Lists
            ->setModel(new BaseList('Collections List', self::MODEL_COLLECTION_LIST, 'collections', self::MODEL_COLLECTION))
            ->setModel(new BaseList('Indexes List', self::MODEL_INDEX_LIST, 'indexes', self::MODEL_INDEX))
            ->setModel(new BaseList('Documents List', self::MODEL_DOCUMENT_LIST, 'documents', self::MODEL_DOCUMENT))
            ->setModel(new BaseList('Users List', self::MODEL_USER_LIST, 'users', self::MODEL_USER))
            ->setModel(new BaseList('Sessions List', self::MODEL_SESSION_LIST, 'sessions', self::MODEL_SESSION))
            ->setModel(new BaseList('Logs List', self::MODEL_LOG_LIST, 'logs', self::MODEL_LOG))
            ->setModel(new BaseList('Files List', self::MODEL_FILE_LIST, 'files', self::MODEL_FILE))
            ->setModel(new BaseList('Teams List', self::MODEL_TEAM_LIST, 'teams', self::MODEL_TEAM))
            ->setModel(new BaseList('Memberships List', self::MODEL_MEMBERSHIP_LIST, 'memberships', self::MODEL_MEMBERSHIP))
            ->setModel(new BaseList('Functions List', self::MODEL_FUNCTION_LIST, 'functions', self::MODEL_FUNCTION))
            ->setModel(new BaseList('Tags List', self::MODEL_TAG_LIST, 'tags', self::MODEL_TAG))
            ->setModel(new BaseList('Executions List', self::MODEL_EXECUTION_LIST, 'executions', self::MODEL_EXECUTION))
            ->setModel(new BaseList('Projects List', self::MODEL_PROJECT_LIST, 'projects', self::MODEL_PROJECT, true, false))
            ->setModel(new BaseList('Webhooks List', self::MODEL_WEBHOOK_LIST, 'webhooks', self::MODEL_WEBHOOK, true, false))
            ->setModel(new BaseList('API Keys List', self::MODEL_KEY_LIST, 'keys', self::MODEL_KEY, true, false))
            ->setModel(new BaseList('Platforms List', self::MODEL_PLATFORM_LIST, 'platforms', self::MODEL_PLATFORM, true, false))
            ->setModel(new BaseList('Domains List', self::MODEL_DOMAIN_LIST, 'domains', self::MODEL_DOMAIN, true, false))
            ->setModel(new BaseList('Countries List', self::MODEL_COUNTRY_LIST, 'countries', self::MODEL_COUNTRY))
            ->setModel(new BaseList('Continents List', self::MODEL_CONTINENT_LIST, 'continents', self::MODEL_CONTINENT))
            ->setModel(new BaseList('Languages List', self::MODEL_LANGUAGE_LIST, 'languages', self::MODEL_LANGUAGE))
            ->setModel(new BaseList('Currencies List', self::MODEL_CURRENCY_LIST, 'currencies', self::MODEL_CURRENCY))
            ->setModel(new BaseList('Phones List', self::MODEL_PHONE_LIST, 'phones', self::MODEL_PHONE))
            ->setModel(new BaseList('Metric List', self::MODEL_METRIC_LIST, 'metrics', self::MODEL_METRIC, true, false))
            // Entities
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
            ->setModel(new Index())
            ->setModel(new ModelDocument())
            ->setModel(new Log())
            ->setModel(new User())
            ->setModel(new Preferences())
            ->setModel(new Session())
            ->setModel(new Token())
            ->setModel(new JWT())
            ->setModel(new Locale())
            ->setModel(new File())
            ->setModel(new Team())
            ->setModel(new Membership())
            ->setModel(new Func())
            ->setModel(new Tag())
            ->setModel(new Execution())
            ->setModel(new Project())
            ->setModel(new Webhook())
            ->setModel(new Key())
            ->setModel(new Domain())
            ->setModel(new Platform())
            ->setModel(new Country())
            ->setModel(new Continent())
            ->setModel(new Language())
            ->setModel(new Currency())
            ->setModel(new Phone())
            ->setModel(new Metric())
            ->setModel(new UsageDatabase())
            ->setModel(new UsageCollection())
            ->setModel(new UsageUsers())
            ->setModel(new UsageStorage())
            ->setModel(new UsageBuckets())
            ->setModel(new UsageFunctions())
            ->setModel(new UsageProject())
            // Verification
            // Recovery
            // Tests (keep last)
            ->setModel(new Mock())
        ;

        parent::__construct($response);
    }

    /**
     * HTTP content types
     */
    const CONTENT_TYPE_YAML = 'application/x-yaml';

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
     * @return Model
     */
    public function getModel(string $key): Model
    {
        if (!isset($this->models[$key])) {
            throw new Exception('Undefined model: '.$key);
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

    /**
     * Validate response objects and outputs
     *  the response according to given format type
     *
     * @param Document $document
     * @param string $model
     *
     * return void
     */
    public function dynamic(Document $document, string $model): void
    {
        $output = $this->output($document, $model);

        // If filter is set, parse the output
        if (self::isFilter()) {
            $output = self::getFilter()->parse($output, $model);
        }

        $this->json(!empty($output) ? $output : new \stdClass());
    }

    /**
     * Generate valid response object from document data
     *
     * @param Document $document
     * @param string $model
     *
     * return array
     */
    public function output(Document $document, string $model): array
    {
        $data       = $document;
        $model      = $this->getModel($model);
        $output     = [];

        if ($model->isAny()) {
            $this->payload = $document->getArrayCopy();
            return $this->payload;
        }

        $document = $model->filter($document);

        foreach ($model->getRules() as $key => $rule) {
            if (!$document->isSet($key) && $rule['require']) { // do not set attribute in response if not required
                if (!is_null($rule['default'])) {
                    $document->setAttribute($key, $rule['default']);
                } else {
                    throw new Exception('Model '.$model->getName().' is missing response key: '.$key);
                }
            }

            if ($rule['array']) {
                if (!is_array($data[$key])) {
                    throw new Exception($key.' must be an array of type '.$rule['type']);
                }

                foreach ($data[$key] as &$item) {
                    if ($item instanceof Document) {
                        if (\is_array($rule['type'])) {
                            foreach ($rule['type'] as $type) {
                                $condition = false;
                                foreach ($this->getModel($type)->conditions as $attribute => $val) {
                                    $condition = $item->getAttribute($attribute) === $val;
                                    if(!$condition) {
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
                            throw new Exception('Missing model for rule: '. $ruleType);
                        }

                        $item = $this->output($item, $ruleType);
                    }
                }
            }

            $output[$key] = $data[$key];
        }

        $this->payload = $output;

        return $this->payload;
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
     */
    public function yaml(array $data): void
    {
        if (!extension_loaded('yaml')) {
            throw new Exception('Missing yaml extension. Learn more at: https://www.php.net/manual/en/book.yaml.php');
        }

        $this
            ->setContentType(Response::CONTENT_TYPE_YAML)
            ->send(yaml_emit($data, YAML_UTF8_ENCODING))
        ;
    }

    /**
     * @return array
     */
    public function getPayload():array
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
    public static function isFilter(): bool
    {
        return self::$filter != null;
    }
}
