<?php

namespace Appwrite\Platform\Modules\Databases\Services;

use Appwrite\Platform\Modules\Databases\Http\Init\Timeout;
use Appwrite\Platform\Modules\Databases\Services\Registry\Collections as CollectionsRegistry;
use Appwrite\Platform\Modules\Databases\Services\Registry\Databases as DatabasesRegistry;
use Appwrite\Platform\Modules\Databases\Services\Registry\Tables as TablesRegistry;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Project database timeout init hook!
        $this->addAction(Timeout::getName(), new Timeout());

        foreach ([
            DatabasesRegistry::class,
            CollectionsRegistry::class,
            TablesRegistry::class,
        ] as $registrar) {
            new $registrar($this);
        }
    }
}
