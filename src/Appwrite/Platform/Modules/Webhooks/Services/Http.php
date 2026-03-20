<?php

namespace Appwrite\Platform\Modules\Webhooks\Services;

use Appwrite\Platform\Modules\Webhooks\Http\Init;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Create as CreateWebhook;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Delete as DeleteWebhook;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Get as GetWebhook;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Signature\Update as UpdateWebhookSignature;
use Appwrite\Platform\Modules\Webhooks\Http\Webhooks\Update as UpdateWebhook;
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
        $this->addAction(CreateWebhook::getName(), new CreateWebhook());
        $this->addAction(ListWebhooks::getName(), new ListWebhooks());
        $this->addAction(GetWebhook::getName(), new GetWebhook());
        $this->addAction(DeleteWebhook::getName(), new DeleteWebhook());
        $this->addAction(UpdateWebhook::getName(), new UpdateWebhook());
        $this->addAction(UpdateWebhookSignature::getName(), new UpdateWebhookSignature());
    }
}
