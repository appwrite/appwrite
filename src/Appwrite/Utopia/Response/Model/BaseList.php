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
                'type' => 'intgere',
                'description' => 'Total sum of items in the list.',
                'example' => '5e5ea5c16897e',
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