<?php

namespace Appwrite\Platform\Modules\Notifications\Services;

use Appwrite\Platform\Modules\Notifications\Http\Notifications\Logos\Appwrite\Get as GetAppwriteLogo;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(GetAppwriteLogo::getName(), new GetAppwriteLogo());
    }
}
