<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class None extends Model
{
    /**
     * @var bool
     */
    protected bool $none = true;

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'None';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_NONE;
    }
}
