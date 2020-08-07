<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class FunctionxList extends BaseList
{
    public function __construct()
    {
        parent::__construct();
        $this
            ->addRule('functions', [
                'type' => Response::MODEL_MEMBERSHIP,
                'description' => 'List of functions.',
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
        return 'Functions List';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_FUNCTION_LIST;
    }
}