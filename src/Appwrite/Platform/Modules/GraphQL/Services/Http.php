<?php

namespace Appwrite\Platform\Modules\GraphQL\Services;

use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
    }
}
