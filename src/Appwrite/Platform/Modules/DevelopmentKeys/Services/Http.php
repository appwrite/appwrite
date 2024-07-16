<?php

namespace Appwrite\Platform\Modules\DevelopmentKeys\Services;

use Appwrite\Platform\Modules\DevelopmentKeys\Http\Create;
use Appwrite\Platform\Modules\DevelopmentKeys\Http\Update;
use Appwrite\Platform\Modules\DevelopmentKeys\Http\Get;
use Appwrite\Platform\Modules\DevelopmentKeys\Http\XList;
use Appwrite\Platform\Modules\DevelopmentKeys\Http\Delete;
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
