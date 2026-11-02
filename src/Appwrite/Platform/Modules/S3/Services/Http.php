<?php

namespace Appwrite\Platform\Modules\S3\Services;

use Appwrite\Platform\Modules\S3\Http\Init;
use Appwrite\Platform\Modules\S3\Http\S3\Create;
use Appwrite\Platform\Modules\S3\Http\S3\Delete;
use Appwrite\Platform\Modules\S3\Http\S3\Get;
use Appwrite\Platform\Modules\S3\Http\Shutdown\Events as ShutdownEvents;
use Appwrite\Platform\Modules\S3\Http\Shutdown\Usage as ShutdownUsage;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(Init::getName(), Init::class);
        $this->addAction(Get::getName(), Get::class);
        $this->addAction(Create::getName(), Create::class);
        $this->addAction(Delete::getName(), Delete::class);
        $this->addAction(ShutdownEvents::getName(), ShutdownEvents::class);
        $this->addAction(ShutdownUsage::getName(), ShutdownUsage::class);
    }
}
