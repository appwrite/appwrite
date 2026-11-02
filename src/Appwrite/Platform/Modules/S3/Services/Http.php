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

        $this->addAction('init', Init::class);
        $this->addAction('s3Get', Get::class);
        $this->addAction('s3Create', Create::class);
        $this->addAction('s3Delete', Delete::class);
        $this->addAction('shutdownEvents', ShutdownEvents::class);
        $this->addAction('shutdownUsage', ShutdownUsage::class);
    }
}
