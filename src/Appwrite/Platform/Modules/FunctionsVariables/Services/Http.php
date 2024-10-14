<?php

namespace Appwrite\Platform\Modules\FunctionsVariables\Services;

use Appwrite\Platform\Modules\FunctionsVariables\Http\Create;
use Appwrite\Platform\Modules\FunctionsVariables\Http\Delete;
use Appwrite\Platform\Modules\FunctionsVariables\Http\Get;
use Appwrite\Platform\Modules\FunctionsVariables\Http\Update;
use Appwrite\Platform\Modules\FunctionsVariables\Http\XList;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction(Create::getName(), new Create());
        $this->addAction(Update::getName(), new Update());
        $this->addAction(Get::getName(), new Get());
        $this->addAction(XList::getName(), new XList());
        $this->addAction(Delete::getName(), new Delete());
    }
}
