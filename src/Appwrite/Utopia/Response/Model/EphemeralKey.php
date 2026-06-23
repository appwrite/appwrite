<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class EphemeralKey extends Key
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Ephemeral Key';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_EPHEMERAL_KEY;
    }
}
