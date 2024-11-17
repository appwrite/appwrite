<?php

namespace Appwrite\Platform\Modules\Tokens\Services;

use Appwrite\Platform\Modules\Tokens\Http\Tokens\ListTokens;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction(ListTokens::getName(), new ListTokens());
    }
}
