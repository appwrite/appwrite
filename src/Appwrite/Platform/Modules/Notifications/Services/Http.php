<?php

namespace Appwrite\Platform\Modules\Notifications\Services;

use Appwrite\Platform\Modules\Notifications\Http\Notifications\Logos\Appwrite\Get as GetAppwriteLogo;
use Appwrite\Platform\Modules\Notifications\Http\Notifications\Update as UpdateNotification;
use Appwrite\Platform\Modules\Notifications\Http\Notifications\XList as ListNotifications;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this
            ->addAction(ListNotifications::getName(), new ListNotifications())
            ->addAction(UpdateNotification::getName(), new UpdateNotification())
            ->addAction(GetAppwriteLogo::getName(), new GetAppwriteLogo());
    }
}
