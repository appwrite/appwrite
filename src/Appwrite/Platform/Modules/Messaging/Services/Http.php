<?php

namespace Appwrite\Platform\Modules\Messaging\Services;

use Appwrite\Platform\Modules\Messaging\Http\Providers\Delete as DeleteProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Get as GetProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Mailgun\Create as CreateMailgunProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\Mailgun\Update as UpdateMailgunProvider;
use Appwrite\Platform\Modules\Messaging\Http\Providers\XList as ListProviders;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Providers
        $this->addAction(CreateMailgunProvider::getName(), new CreateMailgunProvider());
        $this->addAction(UpdateMailgunProvider::getName(), new UpdateMailgunProvider());
        $this->addAction(ListProviders::getName(), new ListProviders());
        $this->addAction(GetProvider::getName(), new GetProvider());
        $this->addAction(DeleteProvider::getName(), new DeleteProvider());
    }
}
