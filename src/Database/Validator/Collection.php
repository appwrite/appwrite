<?php

namespace Database\Validator;

use Database\Document;
use Utopia\Validator;

class Collection extends Validator
{
    /**
     * @var Document
     */
    protected $whitelist = [];

    /**
     * Structure constructor.
     *
     * @param array $whitelist
     */
    public function __construct(array $whitelist)
    {
        $this->whitelist = $whitelist;
    }

    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription()
    {
        if(count($this->whitelist) <= 1) {
            return 'Collection must be of type: [' . implode(',', $this->whitelist) . ']';
        }

        return 'Collection must be one of this types: [' . implode(',', $this->whitelist) . ']';
    }

    /**
     * Is valid
     *
     * Returns true if valid or false if not.
     *
     * @param array $document
     * @return bool
     */
    public function isValid($document) /* @var $document Document */
    {
        if(!$document instanceof Document) {
            return false;
        }

        if(!in_array($document->getCollection(), $this->whitelist)) {
            return false;
        }

        return true;
    }
}