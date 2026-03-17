<?php

namespace Appwrite\Platform\Modules\Webhooks\Services;

use Appwrite\Platform\Modules\Webhooks\Http\Init;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Get as GetWebhook;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\XList as ListWebhooks;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Hooks
        $this->addAction(Init::getName(), new Init());

        // Webhooks
        $this->addAction(ListWebhooks::getName(), new ListWebhooks());
        $this->addAction(GetWebhook::getName(), new GetWebhook());
    }
}
