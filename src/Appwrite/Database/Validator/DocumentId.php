<?php

namespace Appwrite\Database\Validator;

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Utopia\Validator;

class DocumentId extends Validator
{
    /**
     * @var string
     */
    protected $message = 'Document not found.';

    /**
     * @var Database
     */
    protected $database;

    /**
     * @var string
     */
    protected $collection = '';

    /**
     * Structure constructor.
     *
     * @param Database $database
     * @param string $collection
     */
    public function __construct(Database $database, string $collection = '')
    {
        $this->database = $database;
        $this->collection = $collection;
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
        return $this->message;
    }

    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param $value
     *
     * @return bool
     */
    public function isValid($id)
    {
        $document = $this->database->getDocument($id);
        
        if (!$document) {
            return false;
        }
        
        if (!$document instanceof Document) {
            return false;
        }

        if (!$document->getId()) {
            return false;
        }

        if ($document->getCollection() !== $this->collection) {
            return false;
        }

        return true;
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
        return self::TYPE_STRING;
    }
}
