<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class UsageSite extends UsageFunction
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
        return 'UsageSite';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_SITE;
    }
}
