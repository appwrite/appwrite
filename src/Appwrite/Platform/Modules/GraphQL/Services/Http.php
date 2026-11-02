<?php

namespace Appwrite\Platform\Modules\GraphQL\Services;

use Appwrite\Platform\Modules\GraphQL\Http\GraphQL\Create as CreateQuery;
use Appwrite\Platform\Modules\GraphQL\Http\GraphQL\Get as GetQuery;
use Appwrite\Platform\Modules\GraphQL\Http\GraphQL\Mutation\Create as CreateMutation;
use Appwrite\Platform\Modules\GraphQL\Http\Init;
use Appwrite\Platform\Modules\GraphQL\Http\Shutdown;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(Init::getName(), Init::class);
        $this->addAction(GetQuery::getName(), GetQuery::class);
        $this->addAction(CreateMutation::getName(), CreateMutation::class);
        $this->addAction(CreateQuery::getName(), CreateQuery::class);
        $this->addAction(Shutdown::getName(), Shutdown::class);
    }
}
