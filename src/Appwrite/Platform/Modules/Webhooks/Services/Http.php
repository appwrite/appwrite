<?php

namespace Appwrite\Platform\Modules\Webhooks\Services;

use Appwrite\Platform\Modules\Webhooks\Http\Init;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Create as CreateWebhook;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Delete as DeleteWebhook;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Get as GetWebhook;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Secret\Update as UpdateWebhookSecret;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Update as UpdateWebhook;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\XList as ListWebhooks;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Hooks
        $this->addAction(Init::getName(), Init::class);

        // Webhooks
        $this->addAction(CreateWebhook::getName(), CreateWebhook::class);
        $this->addAction(ListWebhooks::getName(), ListWebhooks::class);
        $this->addAction(GetWebhook::getName(), GetWebhook::class);
        $this->addAction(DeleteWebhook::getName(), DeleteWebhook::class);
        $this->addAction(UpdateWebhook::getName(), UpdateWebhook::class);
        $this->addAction(UpdateWebhookSecret::getName(), UpdateWebhookSecret::class);
    }
}
