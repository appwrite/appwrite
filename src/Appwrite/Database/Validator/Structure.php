<?php

namespace Appwrite\Database\Validator;

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Network\Validator as NetworkValidator;
use Utopia\Validator;

class Structure extends Validator
{
    const RULE_TYPE_ID = 'id';
    const RULE_TYPE_PERMISSIONS = 'permissions';
    const RULE_TYPE_KEY = 'key';
    const RULE_TYPE_TEXT = 'text';
    const RULE_TYPE_MARKDOWN = 'markdown';
    const RULE_TYPE_NUMERIC = 'numeric';
    const RULE_TYPE_BOOLEAN = 'boolean';
    const RULE_TYPE_EMAIL = 'email';
    const RULE_TYPE_URL = 'url';
    const RULE_TYPE_IP = 'ip';
    const RULE_TYPE_WILDCARD = 'wildcard';
    const RULE_TYPE_DOCUMENT = 'document';
    const RULE_TYPE_DOCUMENTID = 'documentId';
    const RULE_TYPE_FILEID = 'fileId';

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var string
     */
    protected $id = '';

    /**
     * Basic rules to apply on all documents.
     *
     * @var array
     */
    protected $rules = [
        [
            'label' => '$id',
            '$collection' => Database::SYSTEM_COLLECTION_RULES,
            'key' => '$id',
            'type' => 'id',
            'default' => null,
            'required' => false,
            'array' => false,
        ],
        [
            'label' => '$collection',
            '$collection' => Database::SYSTEM_COLLECTION_RULES,
            'key' => '$collection',
            'type' => 'id',
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
     * @param mixed $document
     *
     * @return bool
     */
    public function isValid($document)
    {
        $document = (\is_array($document)) ? new Document($document) : $document;

        $this->id = $document->getId();

        if (\is_null($document->getCollection())) {
            $this->message = 'Missing collection attribute $collection';

            return false;
        }

        $collection = $this->getCollection($document->getCollection());

        if (\is_null($collection->getId()) || Database::SYSTEM_COLLECTION_COLLECTIONS != $collection->getCollection()) {
            $this->message = 'Collection not found';

            return false;
        }

        $array = $document->getArrayCopy();
        $rules = \array_merge($this->rules, $collection->getAttribute('rules', []));

        foreach ($rules as $rule) { // Check all required keys are set
            if (isset($rule['key']) && !isset($array[$rule['key']])
            && isset($rule['required']) && true == $rule['required']) {
                $this->message = 'Missing required key "'.$rule['key'].'"';

                return false;
            }
        }

        foreach ($array as $key => $value) {
            $rule = $collection->search('key', $key, $rules);
            
            if (!$rule) {
                continue;
            }

            $ruleType = $rule['type'] ?? '';
            $ruleRequired = $rule['required'] ?? true;
            $ruleArray = $rule['array'] ?? false;
            $validator = null;

            switch ($ruleType) {
                case self::RULE_TYPE_ID:
                    $validator = new UID();
                    break;
                case self::RULE_TYPE_PERMISSIONS:
                    $validator = new Permissions($document); //$validator = ($this->forcePermissions) ? new Authorization($original, 'write') : new Validator\Mock();
                    break;
                case self::RULE_TYPE_KEY:
                    $validator = new Key();
                    break;
                case self::RULE_TYPE_TEXT:
                case self::RULE_TYPE_MARKDOWN:
                    $validator = new Validator\Text(0);
                    break;
                case self::RULE_TYPE_NUMERIC:
                    $validator = new Validator\Numeric();
                    break;
                case self::RULE_TYPE_BOOLEAN:
                    $validator = new Validator\Boolean();
                    break;
                case self::RULE_TYPE_EMAIL:
                    $validator = new NetworkValidator\Email();
                    break;
                case self::RULE_TYPE_URL:
                    $validator = new NetworkValidator\URL();
                    break;
                case self::RULE_TYPE_IP:
                    $validator = new NetworkValidator\IP();
                    break;
                case self::RULE_TYPE_WILDCARD:
                    $validator = new Validator\Wildcard();
                    break;
                case self::RULE_TYPE_DOCUMENT:
                    $validator = new Collection($this->database, (isset($rule['list'])) ? $rule['list'] : []);
                    $value = $document->getAttribute($key);
                    break;
                case self::RULE_TYPE_DOCUMENTID:
                    $validator = new DocumentId($this->database, (isset($rule['list']) && isset($rule['list'][0])) ? $rule['list'][0] : '');
                    $value = $document->getAttribute($key);
                    break;
                case self::RULE_TYPE_FILEID:
                    $validator = new DocumentId($this->database, Database::SYSTEM_COLLECTION_FILES);
                    $value = $document->getAttribute($key);
                    break;
            }

            if (empty($validator)) { // Error creating validator for property
                $this->message = 'Unknown rule type "'.$ruleType.'" for property "'.\htmlspecialchars($key, ENT_QUOTES, 'UTF-8').'"';

                if (empty($ruleType)) {
                    $this->message = 'Unknown property "'.$key.'" type'.
                        '. Make sure to follow '.\strtolower($collection->getAttribute('name', 'unknown')).' collection structure';
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
                if (!\is_array($value)) {
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
            $this->message = 'Unknown properties are not allowed ('.\implode(', ', \array_keys($array)).') for this collection'.
                '. Make sure to follow '.\strtolower($collection->getAttribute('name', 'unknown')).' collection structure';

            return false;
        }

        return true;
    }

    /**
     * Get Collection
     *
     * Get Collection by unique ID
     *
     * @return Document
     */
    protected function getCollection($id): Document
    {
        return $this->database->getDocument($id);
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }
}
