<?php

namespace Appwrite\Platform\Modules\GraphQL\Services;

use Appwrite\Platform\Modules\GraphQL\Http\GraphQL\Get as GetQuery;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(GetQuery::getName(), GetQuery::class);
    }
}
