<?php

namespace Appwrite\Platform\Modules\Badge\Services;

use Appwrite\Platform\Modules\Badge\Http\Functions\Get as GetFunctionBadge;
use Appwrite\Platform\Modules\Badge\Http\Sites\Get as GetSiteBadge;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(GetSiteBadge::getName(), new GetSiteBadge());
        $this->addAction(GetFunctionBadge::getName(), new GetFunctionBadge());
    }
}
