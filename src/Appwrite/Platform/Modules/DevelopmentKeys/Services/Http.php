<?php

namespace Appwrite\Platform\Modules\DevelopmentKeys\Services;

use Appwrite\Platform\Modules\DevelopmentKeys\Http\Create;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction(Create::getName(), new Create());
    }
}
