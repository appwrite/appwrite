<?php

namespace Appwrite\Utopia;

use Exception;
use Utopia\Swoole\Response as SwooleResponse;
use Swoole\Http\Response as SwooleHTTPResponse;
use Appwrite\Database\Document;
use Appwrite\Utopia\Response\Filter;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Model\None;
use Appwrite\Utopia\Response\Model\Any;
use Appwrite\Utopia\Response\Model\Attribute;
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
use Appwrite\Utopia\Response\Model\Permissions;
use Appwrite\Utopia\Response\Model\Phone;
use Appwrite\Utopia\Response\Model\Platform;
use Appwrite\Utopia\Response\Model\Project;
use Appwrite\Utopia\Response\Model\Rule;
use Appwrite\Utopia\Response\Model\Tag;
use Appwrite\Utopia\Response\Model\Task;
use Appwrite\Utopia\Response\Model\Token;
use Appwrite\Utopia\Response\Model\Webhook;
use Appwrite\Utopia\Response\Model\Preferences;
use Appwrite\Utopia\Response\Model\Mock; // Keep last
use stdClass;
use Utopia\Database\Document as DatabaseDocument;

/**
 * @method Response public function setStatusCode(int $code = 200)
 */
class Response extends SwooleResponse
{
    // General
    const MODEL_NONE = 'none';
    const MODEL_ANY = 'any';
    const MODEL_LOG = 'log';
    const MODEL_LOG_LIST = 'logList';
    const MODEL_ERROR = 'error';
    const MODEL_ERROR_DEV = 'errorDev';
    const MODEL_BASE_LIST = 'baseList';
    const MODEL_PERMISSIONS = 'permissions';
    
    // Database
    const MODEL_COLLECTION = 'collection';
    const MODEL_COLLECTION_LIST = 'collectionList';
    const MODEL_ATTRIBUTE = 'attribute';
    const MODEL_ATTRIBUTE_LIST = 'attributeList';
    const MODEL_INDEX = 'index';
    const MODEL_INDEX_LIST = 'indexList';
    const MODEL_RULE = 'rule';
    const MODEL_DOCUMENT = 'document';
    const MODEL_DOCUMENT_LIST = 'documentList';

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
    const MODEL_BUCKET = 'bucket';
    const MODEL_BUCKET_LIST = 'bucketList';

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
    const MODEL_TASK = 'task';
    const MODEL_TASK_LIST = 'taskList';
    const MODEL_PLATFORM = 'platform';
    const MODEL_PLATFORM_LIST = 'platformList';
    const MODEL_DOMAIN = 'domain';
    const MODEL_DOMAIN_LIST = 'domainList';
    
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
            ->setModel(new BaseList('Attributes List', self::MODEL_ATTRIBUTE_LIST, 'attributes', self::MODEL_ATTRIBUTE))
            ->setModel(new BaseList('Indexes List', self::MODEL_INDEX_LIST, 'indexes', self::MODEL_INDEX))
            ->setModel(new BaseList('Documents List', self::MODEL_DOCUMENT_LIST, 'documents', self::MODEL_DOCUMENT))
            ->setModel(new BaseList('Users List', self::MODEL_USER_LIST, 'users', self::MODEL_USER))
            ->setModel(new BaseList('Sessions List', self::MODEL_SESSION_LIST, 'sessions', self::MODEL_SESSION))
            ->setModel(new BaseList('Logs List', self::MODEL_LOG_LIST, 'logs', self::MODEL_LOG, false))
            ->setModel(new BaseList('Files List', self::MODEL_FILE_LIST, 'files', self::MODEL_FILE))
            ->setModel(new BaseList('Buckets List', self::MODEL_BUCKET_LIST, 'buckets', self::MODEL_BUCKET))
            ->setModel(new BaseList('Teams List', self::MODEL_TEAM_LIST, 'teams', self::MODEL_TEAM))
            ->setModel(new BaseList('Memberships List', self::MODEL_MEMBERSHIP_LIST, 'memberships', self::MODEL_MEMBERSHIP))
            ->setModel(new BaseList('Functions List', self::MODEL_FUNCTION_LIST, 'functions', self::MODEL_FUNCTION))
            ->setModel(new BaseList('Tags List', self::MODEL_TAG_LIST, 'tags', self::MODEL_TAG))
            ->setModel(new BaseList('Executions List', self::MODEL_EXECUTION_LIST, 'executions', self::MODEL_EXECUTION))
            ->setModel(new BaseList('Projects List', self::MODEL_PROJECT_LIST, 'projects', self::MODEL_PROJECT, true, false))
            ->setModel(new BaseList('Webhooks List', self::MODEL_WEBHOOK_LIST, 'webhooks', self::MODEL_WEBHOOK, true, false))
            ->setModel(new BaseList('API Keys List', self::MODEL_KEY_LIST, 'keys', self::MODEL_KEY, true, false))
            ->setModel(new BaseList('Tasks List', self::MODEL_TASK_LIST, 'tasks', self::MODEL_TASK, true, false))
            ->setModel(new BaseList('Platforms List', self::MODEL_PLATFORM_LIST, 'platforms', self::MODEL_PLATFORM, true, false))
            ->setModel(new BaseList('Domains List', self::MODEL_DOMAIN_LIST, 'domains', self::MODEL_DOMAIN, true, false))
            ->setModel(new BaseList('Countries List', self::MODEL_COUNTRY_LIST, 'countries', self::MODEL_COUNTRY))
            ->setModel(new BaseList('Continents List', self::MODEL_CONTINENT_LIST, 'continents', self::MODEL_CONTINENT))
            ->setModel(new BaseList('Languages List', self::MODEL_LANGUAGE_LIST, 'languages', self::MODEL_LANGUAGE))
            ->setModel(new BaseList('Currencies List', self::MODEL_CURRENCY_LIST, 'currencies', self::MODEL_CURRENCY))
            ->setModel(new BaseList('Phones List', self::MODEL_PHONE_LIST, 'phones', self::MODEL_PHONE))
            // Entities
            ->setModel(new Permissions())
            ->setModel(new Collection())
            ->setModel(new Attribute())
            ->setModel(new Index())
            ->setModel(new ModelDocument())
            ->setModel(new Rule())
            ->setModel(new Log())
            ->setModel(new User())
            ->setModel(new Preferences())
            ->setModel(new Session())
            ->setModel(new Token())
            ->setModel(new JWT())
            ->setModel(new Locale())
            ->setModel(new File())
            ->setModel(new Bucket())
            ->setModel(new Team())
            ->setModel(new Membership())
            ->setModel(new Func())
            ->setModel(new Tag())
            ->setModel(new Execution())
            ->setModel(new Project())
            ->setModel(new Webhook())
            ->setModel(new Key())
            ->setModel(new Task())
            ->setModel(new Domain())
            ->setModel(new Platform())
            ->setModel(new Country())
            ->setModel(new Continent())
            ->setModel(new Language())
            ->setModel(new Currency())
            ->setModel(new Phone())
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
        if(self::isFilter()){
            $output = self::getFilter()->parse($output, $model);
        }

        $this->json(!empty($output) ? $output : new stdClass());
    }

    /**
     * Validate response objects and outputs
     *  the response according to given format type
     * 
     * @param DatabaseDocument $document
     * @param string $model
     * 
     * return void
     */
    public function dynamic2(DatabaseDocument $document, string $model): void
    {
        $output = $this->output2($document, $model);

        // If filter is set, parse the output
        if(self::isFilter()){
            $output = self::getFilter()->parse($output, $model);
        }

        $this->json(!empty($output) ? $output : new stdClass());
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

        foreach ($model->getRules() as $key => $rule) {
            if (!$document->isSet($key)) {
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
                        if (!array_key_exists($rule['type'], $this->models)) {
                            throw new Exception('Missing model for rule: '. $rule['type']);
                        }

                        $item = $this->output($item, $rule['type']);
                    }
                }
            }
            
            $output[$key] = $data[$key];
        }

        $this->payload = $output;

        return $this->payload;
    }

    /**
     * Generate valid response object from document data
     * 
     * @param DatabaseDocument $document
     * @param string $model
     * 
     * return array
     */
    public function output2(DatabaseDocument $document, string $model): array
    {
        $data       = $document;
        $model      = $this->getModel($model);
        $output     = [];

        if ($model->isAny()) {
            $this->payload = $document->getArrayCopy();
            return $this->payload;
        }

        foreach ($model->getRules() as $key => $rule) {
            if (!$document->isSet($key)) {
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
                        if (!array_key_exists($rule['type'], $this->models)) {
                            throw new Exception('Missing model for rule: '. $rule['type']);
                        }

                        $item = $this->output($item, $rule['type']);
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
