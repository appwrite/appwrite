<?php

namespace Database\Validator;

use Database\Database;
use Database\Document;
use Utopia\Validator;

class Structure extends Validator
{
    /**
     * @var Database
     */
    protected $database;

    /**
     * @var int
     */
    protected $id = null;

    /**
     * Basic rules to apply on all documents.
     *
     * @var array
     */
    protected $rules = [
        [
            'label' => '$uid',
            '$collection' => Database::SYSTEM_COLLECTION_RULES,
            'key' => '$uid',
            'type' => 'uid',
            'default' => null,
            'required' => false,
            'array' => false,
        ],
        [
            'label' => '$collection',
            '$collection' => Database::SYSTEM_COLLECTION_RULES,
            'key' => '$collection',
            'type' => 'uid',
            'default' => null,
            'required' => true,
            'array' => false,
        ],
        [
            'label' => '$permissions',
            '$collection' => Database::SYSTEM_COLLECTION_RULES,
            'key' => '$permissions',
            'type' => 'permissions',
            'default' => null,
            'required' => true,
            'array' => false,
        ],
        [
            'label' => '$createdAt',
            '$collection' => Database::SYSTEM_COLLECTION_RULES,
            'key' => '$createdAt',
            'type' => 'numeric',
            'default' => null,
            'required' => false,
            'array' => false,
        ],
        [
            'label' => '$updatedAt',
            '$collection' => Database::SYSTEM_COLLECTION_RULES,
            'key' => '$updatedAt',
            'type' => 'numeric',
            'default' => null,
            'required' => false,
            'array' => false,
        ],
    ];

    /**
     * @var string
     */
    protected $message = 'General Error';

    /**
     * Structure constructor.
     *
     * @param Database $database
     */
    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Invalid document structure: '.$this->message;
    }

    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param Document $document
     *
     * @return bool
     */
    public function isValid($document)
    {
        $document = (is_array($document)) ? new Document($document) : $document;

        $this->id = $document->getUid();

        if (is_null($document->getCollection())) {
            $this->message = 'Missing collection attribute $collection';

            return false;
        }

        $collection = $this->getCollection($document->getCollection());

        if (is_null($collection->getUid()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            $this->message = 'Collection not found';

            return false;
        }

        $array = $document->getArrayCopy();
        $rules = array_merge($this->rules, $collection->getAttribute('rules', []));

        foreach ($rules as $rule) { // Check all required keys are set
            if (isset($rule['key']) && !isset($array[$rule['key']])
            && isset($rule['required']) && true == $rule['required']) {
                $this->message = 'Missing required key "'.$rule['key'].'"';

                return false;
            }
        }

        foreach ($array as $key => $value) {
            $rule = $collection->search('key', $key, $rules);
            $ruleType = (isset($rule['type'])) ? $rule['type'] : '';
            $ruleRequired = (isset($rule['required'])) ? $rule['required'] : true;
            $ruleArray = (isset($rule['array'])) ? $rule['array'] : false;
            $validator = null;

            switch ($ruleType) {
                case 'uid':
                    $validator = new UID();
                    break;
                case 'text':
                    $validator = new Validator\Text(0);
                    break;
                case 'numeric':
                    $validator = new Validator\Numeric();
                    break;
                case 'boolean':
                    $validator = new Validator\Boolean();
                    break;
                case 'ip':
                    $validator = new Validator\IP();
                    break;
                case 'email':
                    $validator = new Validator\Email();
                    break;
                case 'url':
                    $validator = new Validator\URL();
                    break;
                case 'wildcard':
                    $validator = new Validator\Mock();
                    break;
                case 'permissions':
                    $validator = new Permissions($document); //$validator = ($this->forcePermissions) ? new Authorization($original, 'write') : new Validator\Mock();
                    break;
                case 'key':
                    $validator = new Key();
                    break;
                case 'document':
                    $validator = new Collection($this->database, (isset($rule['list'])) ? $rule['list'] : []);
                    // $validator = new Collection($this->database, (isset($rule['list'])) ? $rule['list'] : [],
                    //     ['$permissions' => (isset($document['$permissions'])) ? $document['$permissions'] : []]);
                    $value = $document->getAttribute($key);
                    break;
            }

            if (empty($validator)) { // Error creating validator for property
                $this->message = 'Unknown rule type "'.$ruleType.'" for property "'.htmlspecialchars($key, ENT_QUOTES, 'UTF-8').'"';

                if (empty($ruleType)) {
                    $this->message = 'Unknown property "'.$key.'" type'.
                        '. Make sure to follow '.strtolower($collection->getAttribute('name', 'unknown')).' collection structure';
                }

                return false;
            }

            if ($ruleRequired && ('' === $value || null === $value)) {
                $this->message = 'Required property "'.$key.'" has no value';

                return false;
            }

            if (!$ruleRequired && empty($value)) {
                unset($array[$key]);
                unset($rule);

                continue;
            }

            if ($ruleArray) { // Array of values validation
                if (!is_array($value)) {
                    $this->message = 'Property "'.$key.'" must be an array';

                    return false;
                }

                // TODO add is required check here

                foreach ($value as $node) {
                    if (!$validator->isValid($node)) { // Check if property is valid, if not required can also be empty
                        $this->message = 'Property "'.$key.'" has invalid input. '.$validator->getDescription();

                        return false;
                    }
                }
            } else { // Single value validation
                if ((!$validator->isValid($value)) && !('' === $value && !$ruleRequired)) {  // Error when value is not valid, and is not optional and empty
                    $this->message = 'Property "'.$key.'" has invalid input. '.$validator->getDescription();

                    return false;
                }
            }

            unset($array[$key]);
            unset($rule);
        }

        if (!empty($array)) { // No fields should be left unvalidated
            $this->message = 'Unknown properties are not allowed ('.implode(', ', array_keys($array)).') for this collection'.
                '. Make sure to follow '.strtolower($collection->getAttribute('name', 'unknown')).' collection structure';

            return false;
        }

        return true;
    }

    protected function getCollection($uid)
    {
        return $this->database->getDocument($uid);
    }
}
