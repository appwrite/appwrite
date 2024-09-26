<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Payload extends Model
{
    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Payload';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PAYLOAD;
    }
}
