<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

abstract class BaseList extends Model
{
    public function __construct()
    {
        $this
            ->addRule('sum', [
                'type' => 'integer',
                'description' => 'Total sum of items in the list.',
                'example' => '5',
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
        return 'Base List';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_BASE_LIST;
    }
}