<?php

namespace Appwrite\Utopia;

use Exception;
use Appwrite\Database\Document;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Model\BaseList;
use Appwrite\Utopia\Response\Model\Error;
use Appwrite\Utopia\Response\Model\ErrorDev;
use Appwrite\Utopia\Response\Model\Execution;
use Appwrite\Utopia\Response\Model\File;
use Appwrite\Utopia\Response\Model\Func;
use Appwrite\Utopia\Response\Model\User;
use Appwrite\Utopia\Response\Model\Session;
use Appwrite\Utopia\Response\Model\Team;
use Appwrite\Utopia\Response\Model\Locale;
use Appwrite\Utopia\Response\Model\Membership;
use Appwrite\Utopia\Response\Model\Tag;
use Utopia\Response as UtopiaResponse;

class Response extends UtopiaResponse
{
    // General
    const MODEL_LOG = 'log'; // - Missing
    const MODEL_ERROR = 'error';
    const MODEL_ERROR_DEV = 'errorDev';
    const MODEL_BASE_LIST = 'baseList';
    const MODEL_PERMISSIONS = 'permissions';
    
    // Users
    const MODEL_USER = 'user';
    const MODEL_SESSION = 'session';
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

    /**
     * Response constructor.
     */
    public function __construct(int $time = 0)
    {
        $this
            // General
            ->setModel(new Error())
            ->setModel(new ErrorDev())
            // Lists
            ->setModel(new BaseList('Users List', self::MODEL_FILE_LIST, 'users', self::MODEL_USER))
            ->setModel(new BaseList('Files List', self::MODEL_FILE_LIST, 'files', self::MODEL_FILE))
            ->setModel(new BaseList('Teams List', self::MODEL_TEAM_LIST, 'teams', self::MODEL_TEAM))
            ->setModel(new BaseList('Memberships List', self::MODEL_MEMBERSHIP_LIST, 'memberships', self::MODEL_MEMBERSHIP))
            ->setModel(new BaseList('Functions List', self::MODEL_FUNCTION_LIST, 'functions', self::MODEL_FUNCTION))
            ->setModel(new BaseList('Tags List', self::MODEL_TAG_LIST, 'tags', self::MODEL_TAG))
            ->setModel(new BaseList('Executions List', self::MODEL_EXECUTION_LIST, 'executions', self::MODEL_EXECUTION))
            // Entities
            ->setModel(new User())
            ->setModel(new Session())
            ->setModel(new Locale())
            ->setModel(new File())
            ->setModel(new Team())
            ->setModel(new Membership())
            ->setModel(new Func())
            ->setModel(new Tag())
            ->setModel(new Execution())
        ;

        parent::__construct($time);
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
                    var_dump($data);
                    throw new Exception('Missing response key: '.$key);
                }
            }

            if($rule['array']) {
                if(!is_array($data[$key])) {
                    var_dump($data);
                    throw new Exception($key.' must be an array of type '.$rule['type']);
                }

                foreach ($data[$key] as &$item) {
                    if(array_key_exists($rule['type'], $this->models) && $item instanceof Document) {
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
