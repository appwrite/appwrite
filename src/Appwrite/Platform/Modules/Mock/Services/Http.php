<?php

namespace Appwrite\Platform\Modules\Mock\Services;

use Appwrite\Platform\Modules\Mock\Http\Shutdown;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(Shutdown::getName(), Shutdown::class);
    }
}
