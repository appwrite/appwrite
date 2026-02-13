<?php

namespace Appwrite\Platform\Modules\Schedules\Services;

use Appwrite\Platform\Modules\Schedules\Http\Schedules\Create;
use Appwrite\Platform\Modules\Schedules\Http\Schedules\Get;
use Appwrite\Platform\Modules\Schedules\Http\Schedules\XList;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(Get::getName(), new Get());
        $this->addAction(XList::getName(), new XList());
        $this->addAction(Create::getName(), new Create());
    }
}
