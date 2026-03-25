<?php

namespace Appwrite\Platform\Modules\Presence\Services;

use Appwrite\Platform\Modules\Presence\HTTP\Create as CreatePresence;
use Appwrite\Platform\Modules\Presence\HTTP\Get as GetPresence;
use Appwrite\Platform\Modules\Presence\HTTP\Iterative\XList as ListPresence;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction(CreatePresence::getName(), new CreatePresence());
        $this->addAction(ListPresence::getName(), new ListPresence());
        $this->addAction(GetPresence::getName(), new GetPresence());
    }
}
