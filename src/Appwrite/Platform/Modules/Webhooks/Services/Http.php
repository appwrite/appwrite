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
        $this->addAction('init', Init::class);

        // Webhooks
        $this->addAction('createWebhook', CreateWebhook::class);
        $this->addAction('listWebhooks', ListWebhooks::class);
        $this->addAction('getWebhook', GetWebhook::class);
        $this->addAction('deleteWebhook', DeleteWebhook::class);
        $this->addAction('updateWebhook', UpdateWebhook::class);
        $this->addAction('updateWebhookSecret', UpdateWebhookSecret::class);
    }
}
