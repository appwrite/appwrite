<?php

namespace Appwrite\Platform\Modules\Sandbox\Services;

use Appwrite\Platform\Modules\Sandbox\Http\Create as CreateSandbox;
use Appwrite\Platform\Modules\Sandbox\Http\Delete as DeleteSandbox;
use Appwrite\Platform\Modules\Sandbox\Http\Get as GetSandbox;
use Appwrite\Platform\Modules\Sandbox\Http\XList as ListSandboxes;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this
            ->addAction(CreateSandbox::getName(), new CreateSandbox())
            ->addAction(GetSandbox::getName(), new GetSandbox())
            ->addAction(ListSandboxes::getName(), new ListSandboxes())
            ->addAction(DeleteSandbox::getName(), new DeleteSandbox());
    }
}
