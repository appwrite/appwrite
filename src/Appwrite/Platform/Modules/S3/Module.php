<?php

namespace Appwrite\Platform\Modules\S3;

use Appwrite\Platform\Modules\S3\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
