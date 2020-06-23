<?php

namespace Appwrite\Utopia;

use Exception;
use Appwrite\Database\Document;
use Appwrite\Utopia\Response\Model;
use Appwrite\Utopia\Response\Model\Error;
use Appwrite\Utopia\Response\Model\ErrorDev;
use Appwrite\Utopia\Response\Model\User;
use Appwrite\Utopia\Response\Model\Locale;
use Utopia\Response as UtopiaResponse;

class Response extends UtopiaResponse
{
    const MODEL_ERROR = 'error';
    const MODEL_ERROR_DEV = 'errorDev';
    const MODEL_USER = 'user';
    const MODEL_LOCALE = 'locale';

    public function __construct()
    {
        $this
            ->setModel(new Error())
            ->setModel(new ErrorDev())
            ->setModel(new User())
            ->setModel(new Locale())
        ;
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
        $data       = $document->getArrayCopy();
        $model      = $this->getModel($model);
        $output     = [];

        foreach($model->getRules() as $key => $rule) {
            if(!isset($data[$key])) {
                if(!is_null($rule['default'])) {
                    $data[$key] = $rule['default'];
                }
                else {
                    throw new Exception('Missing response key: '.$key);
                }
            }

            if($rule['array'] && !is_array($data[$key])) {
                throw new Exception($key.' must be an array of '.$rule['type'].' types');
            }
            
            $output[$key] = $data[$key];
        }

        return $this->json($output);
        //return $this->yaml($output);
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
