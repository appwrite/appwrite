<?php

namespace Appwrite\Platform\Modules\Proxy\Services;

use Appwrite\Platform\Modules\Proxy\Http\Rules\Create as CreateRule;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        // Rules
        $this->addAction(CreateRule::getName(), new CreateRule());
    }
}
