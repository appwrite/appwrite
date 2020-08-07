<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class TagList extends BaseList
{
    public function __construct()
    {
        parent::__construct();
        $this
            ->addRule('tags', [
                'type' => Response::MODEL_TAG,
                'description' => 'List of tags.',
                'example' => [],
                'array' => true,
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Tags List';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_TAG_LIST;
    }
}