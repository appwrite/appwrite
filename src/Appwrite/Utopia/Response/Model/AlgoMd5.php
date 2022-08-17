<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class AlgoMd5 extends Model
{
    public function __construct()
    {
        // No options, because this can only be imported, and verifying doesnt require any configuration
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AlgoMD5';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ALGO_MD5;
    }
}
