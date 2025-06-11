<?php

namespace Appwrite\Platform\Modules\Proxy\Services;

use Appwrite\Platform\Modules\Proxy\Http\Rules\API\Create as CreateAPIRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Function\Create as CreateFunctionRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Redirect\Create as CreateRedirectRule;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Site\Create as CreateSiteRule;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Rules
        $this->addAction(CreateAPIRule::getName(), new CreateAPIRule());
        $this->addAction(CreateSiteRule::getName(), new CreateSiteRule());
        $this->addAction(CreateFunctionRule::getName(), new CreateFunctionRule());
        $this->addAction(CreateRedirectRule::getName(), new CreateRedirectRule());
    }
}
