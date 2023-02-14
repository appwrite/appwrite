<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class Headers extends Any
{
    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Headers';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_HEADERS;
    }
}
