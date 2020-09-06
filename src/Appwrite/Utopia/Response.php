<?php

namespace Appwrite\Utopia;

use Exception;
use Utopia\Swoole\Response as SwooleResponse;
use Swoole\Http\Response as SwooleHTTPResponse;
use Appwrite\Database\Document;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Model\BaseList;
use Appwrite\Utopia\Response\Model\Domain;
use Appwrite\Utopia\Response\Model\Error;
use Appwrite\Utopia\Response\Model\ErrorDev;
use Appwrite\Utopia\Response\Model\Execution;
use Appwrite\Utopia\Response\Model\File;
use Appwrite\Utopia\Response\Model\Func;
use Appwrite\Utopia\Response\Model\Key;
use Appwrite\Utopia\Response\Model\User;
use Appwrite\Utopia\Response\Model\Session;
use Appwrite\Utopia\Response\Model\Team;
use Appwrite\Utopia\Response\Model\Locale;
use Appwrite\Utopia\Response\Model\Log;
use Appwrite\Utopia\Response\Model\Membership;
use Appwrite\Utopia\Response\Model\Platform;
use Appwrite\Utopia\Response\Model\Tag;
use Appwrite\Utopia\Response\Model\Task;
use Appwrite\Utopia\Response\Model\Webhook;

class Response extends SwooleResponse
{
    // General
    const MODEL_LOG = 'log'; // - Missing
    const MODEL_LOG_LIST = 'logList'; // - Missing
    const MODEL_ERROR = 'error';
    const MODEL_ERROR_DEV = 'errorDev';
    const MODEL_BASE_LIST = 'baseList';
    const MODEL_PERMISSIONS = 'permissions';
    
    // Users
    const MODEL_USER = 'user';
    const MODEL_USER_LIST = 'userList';
    const MODEL_SESSION = 'session';
    const MODEL_SESSION_LIST = 'sessionList';
    const MODEL_TOKEN = 'token'; // - Missing

    // Database
    const MODEL_COLLECTION = 'collection'; // - Missing
    
    // Locale
    const MODEL_LOCALE = 'locale';
    const MODEL_COUNTRY = 'country'; // - Missing
    const MODEL_CONTINENT = 'continent'; // - Missing
    const MODEL_CURRENCY = 'currency'; // - Missing
    const MODEL_LANGUAGE = 'langauge'; // - Missing
    const MODEL_PHONE = 'phone'; // - Missing

    // Storage
    const MODEL_FILE = 'file';
    const MODEL_FILE_LIST = 'fileList';
    const MODEL_BUCKET = 'bucket'; // - Missing

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
    const MODEL_PROJECT_LIST = 'projectsList';
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

    /**
     * Response constructor.
     */
    public function __construct(SwooleHTTPResponse $response)
    {
        $this
            // General
            ->setModel(new Error())
            ->setModel(new ErrorDev())
            // Lists
            ->setModel(new BaseList('Users List', self::MODEL_USER_LIST, 'users', self::MODEL_USER))
            ->setModel(new BaseList('Sessions List', self::MODEL_SESSION_LIST, 'sessions', self::MODEL_SESSION))
            ->setModel(new BaseList('Logs List', self::MODEL_LOG_LIST, 'logs', self::MODEL_LOG, false))
            ->setModel(new BaseList('Files List', self::MODEL_FILE_LIST, 'files', self::MODEL_FILE))
            ->setModel(new BaseList('Teams List', self::MODEL_TEAM_LIST, 'teams', self::MODEL_TEAM))
            ->setModel(new BaseList('Memberships List', self::MODEL_MEMBERSHIP_LIST, 'memberships', self::MODEL_MEMBERSHIP))
            ->setModel(new BaseList('Functions List', self::MODEL_FUNCTION_LIST, 'functions', self::MODEL_FUNCTION))
            ->setModel(new BaseList('Tags List', self::MODEL_TAG_LIST, 'tags', self::MODEL_TAG))
            ->setModel(new BaseList('Executions List', self::MODEL_EXECUTION_LIST, 'executions', self::MODEL_EXECUTION))
            ->setModel(new BaseList('Projects List', self::MODEL_PROJECT_LIST, 'projects', self::MODEL_PROJECT))
            ->setModel(new BaseList('Webhooks List', self::MODEL_WEBHOOK_LIST, 'webhooks', self::MODEL_WEBHOOK))
            ->setModel(new BaseList('API Keys List', self::MODEL_KEY_LIST, 'keys', self::MODEL_KEY))
            ->setModel(new BaseList('Tasks List', self::MODEL_TASK_LIST, 'tasks', self::MODEL_TASK))
            ->setModel(new BaseList('Platforms List', self::MODEL_PLATFORM_LIST, 'platforms', self::MODEL_PLATFORM))
            ->setModel(new BaseList('Domains List', self::MODEL_DOMAIN_LIST, 'domains', self::MODEL_DOMAIN))
            // Entities
            ->setModel(new Log())
            ->setModel(new User())
            ->setModel(new Session())
            ->setModel(new Locale())
            ->setModel(new File())
            ->setModel(new Team())
            ->setModel(new Membership())
            ->setModel(new Func())
            ->setModel(new Tag())
            ->setModel(new Execution())
            ->setModel(new Webhook())
            ->setModel(new Key())
            ->setModel(new Task())
            ->setModel(new Domain())
            ->setModel(new Platform())
            // Continent
            // Country
            // Currency
            // Verification
            // Recovery
            // Language
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
    public function setModel(Model $instance): self
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
        if(!isset($this->models[$key])) {
            throw new Exception('Undefined model: '.$key);
        }

        return $this->models[$key];
    }

    /**
     * Validate response objects and outputs
     *  the response according to given format type
     */
    public function dynamic(Document $document, string $model)
    {
        return $this->json($this->output($document, $model));
    }

    /**
     * Generate valid response object from document data
     */
    protected function output(Document $document, string $model): array
    {
        $data       = $document;
        $model      = $this->getModel($model);
        $output     = [];

        foreach($model->getRules() as $key => $rule) {
            if(!$document->isSet($key)) {
                if(!is_null($rule['default'])) {
                    $document->setAttribute($key, $rule['default']);
                }
                else {
                    throw new Exception('Model '.$model->getName().' is missing response key: '.$key);
                }
            }

            if($rule['array']) {
                if(!is_array($data[$key])) {
                    throw new Exception($key.' must be an array of type '.$rule['type']);
                }

                foreach ($data[$key] as &$item) {
                    if($item instanceof Document) {
                        if(!array_key_exists($rule['type'], $this->models)) {
                            throw new Exception('Missing model for rule: '. $rule['type']);
                        }

                        $item = $this->output($item, $rule['type']);
                    }
                }
            }
            
            $output[$key] = $data[$key];
        }

        return $output;
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
     */
    public function yaml(array $data)
    {
        if(!extension_loaded('yaml')) {
            throw new Exception('Missing yaml extension. Learn more at: https://www.php.net/manual/en/book.yaml.php');
        }

        $this
            ->setContentType(Response::CONTENT_TYPE_YAML)
            ->send(yaml_emit($data, YAML_UTF8_ENCODING))
        ;
    }
}
