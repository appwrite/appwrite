<?php

namespace Database\Validator;

use Database\Database;
use Database\Document;

class Collection extends Structure
{
    /**
     * @var string
     */
    protected $message = 'Unknown Error';

    /**
     * @var array
     */
    protected $collections = [];

    /**
     * @param Database $database
     * @param array    $collections
     */
    public function __construct(Database $database, array $collections)
    {
        $this->collections = $collections;

        return parent::__construct($database);
    }

    /**
     * @param Document $document
     *
     * @return bool
     */
    public function isValid($document)
    {
        $document = (is_array($document)) ? new Document($document) : $document;

        if (is_null($document->getCollection())) {
            $this->message = 'Missing collection attribute $collection';

            return false;
        }

        if (!in_array($document->getCollection(), $this->collections)) {
            $this->message = 'Collection is not allowed';

            return false;
        }

        return parent::isValid($document);
    }
}
