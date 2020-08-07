<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ExecutionList extends BaseList
{
    public function __construct()
    {
        parent::__construct();
        $this
            ->addRule('executions', [
                'type' => Response::MODEL_EXECUTION,
                'description' => 'List of function execitions.',
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
        return 'Executions List';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_EXECUTION_LIST;
    }
}