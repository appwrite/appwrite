<?php

namespace Appwrite\Platform\Modules\Logs\Services;

use Appwrite\Platform\Modules\Logs\Http\Delete;
use Appwrite\Platform\Modules\Logs\Http\Get;
use Appwrite\Platform\Modules\Logs\Http\XList;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(XList::getName(), new XList());
        $this->addAction(Get::getName(), new Get());
        $this->addAction(Delete::getName(), new Delete());
    }
}
