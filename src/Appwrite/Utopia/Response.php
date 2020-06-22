<?php

namespace Appwrite\Utopia;

use Exception;
use Appwrite\Database\Document;
use Appwrite\Utopia\Response\Result;
use Appwrite\Utopia\Response\Result\User;
use Utopia\Response as UtopiaResponse;

class Response extends UtopiaResponse
{

    public function __construct()
    {
        $this
            ->setResult(new User())
        ;
    }

    /**
     * HTTP content types
     */
    const CONTENT_TYPE_YAML = 'application/x-yaml';

    /**
     * List of defined output objects
     */
    protected $results = [];

    /**
     * Set Result Object
     * 
     * @return self
     */
    public function setResult(Result $result): self
    {
        $this->results[$result->getCollection()] = $result;

        return $this;
    }

    /**
     * Get Result Object
     * 
     * @return Result
     */
    public function getResult(string $key): Result
    {
        if(!isset($this->results[$key])) {
            throw new Exception('Undefined result: '.$key);
        }

        return $this->results[$key];
    }

    /**
     * Validate response objects and outputs
     *  the response according to given format type
     */
    public function dynamic(Document $document, $type = self::CONTENT_TYPE_JSON)
    {
        $collection = $document->getCollection();
        $data       = $document->getArrayCopy();
        $result     = $this->getResult($collection);
        $output     = [];

        foreach($result->getRules() as $key => $rule) {
            if(!isset($data[$key])) {
                if(!is_null($rule['default'])) {
                    $data[$key] = $rule['default'];
                }
                else {
                    throw new Exception('Missing response key: ' . $key);
                }
            }

            $output[$key] = $data[$key];
        }

        switch ($type) {
            case self::CONTENT_TYPE_JSON:
                return $this->json($output);
                break;
            
            case self::CONTENT_TYPE_YAML:
                return $this->yaml($output);
                break;
            
            default:
                throw new Exception('Unknown content type');
                break;
        }
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
