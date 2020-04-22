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
    protected $database = null;

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
        
        if(!$document) {
            return false;
        }
        
        if(!$document instanceof Document) {
            return false;
        }

        if(!$document->getId()) {
            return false;
        }

        if($document->getCollection() !== Database::SYSTEM_COLLECTION_FILES) {
            return false;
        }

        return true;
    }
}
