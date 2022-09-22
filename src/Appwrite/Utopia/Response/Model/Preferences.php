<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class Preferences extends Any
{
    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Preferences';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PREFERENCES;
    }
}
