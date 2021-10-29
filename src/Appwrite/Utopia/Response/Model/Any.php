<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Any extends Model
{
    /**
     * @var bool
     */
    protected $any = true;

    /**
     * Get Name
     *
     * @return string
     */
    public function getName():string
    {
        return 'Any';
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_ANY;
    }
}
