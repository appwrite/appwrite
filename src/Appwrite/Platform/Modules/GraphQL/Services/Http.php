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

        $this->addAction(Init::getName(), new Init());
        $this->addAction(GetQuery::getName(), new GetQuery());
        $this->addAction(CreateMutation::getName(), new CreateMutation());
        $this->addAction(CreateQuery::getName(), new CreateQuery());
        $this->addAction(Shutdown::getName(), new Shutdown());
    }
}
