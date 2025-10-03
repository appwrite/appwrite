<?php

namespace Appwrite\Platform\Modules\Payments\Services;

use Appwrite\Platform\Modules\Payments\Workers\UsageSync;
use Utopia\Platform\Service;

class Workers extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_WORKER;
        $this->addAction(UsageSync::getName(), new UsageSync());
    }
}


