<?php

namespace Appwrite\Platform\Modules\Videos\Services;

use Appwrite\Platform\Modules\Videos\Workers\Videos;
use Utopia\Platform\Service;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        $this->addAction(Videos::getName(), new Videos());
    }
}
