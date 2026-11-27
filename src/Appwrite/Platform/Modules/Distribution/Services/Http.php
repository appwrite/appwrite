<?php

namespace Appwrite\Platform\Modules\Distribution\Services;

use Appwrite\Platform\Modules\Distribution\Http\Init;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction('init', Init::class);
    }
}
