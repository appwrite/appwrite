<?php

namespace Appwrite\Platform\Modules\Sites;

use Utopia\Platform;

class Module extends Platform\Module
{
    public function __construct()
    {
        $this->addService('http', new Services\Http());
        $this->addService('workers', new Services\Workers());
    }
}
