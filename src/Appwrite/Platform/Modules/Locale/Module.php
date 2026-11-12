<?php

namespace Appwrite\Platform\Modules\Locale;

use Appwrite\Platform\Modules\Locale\Services\Http;
use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Http());
    }
}
