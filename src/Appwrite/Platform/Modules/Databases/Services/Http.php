<?php

namespace Appwrite\Platform\Modules\Databases\Services;

use Appwrite\Platform\Modules\Databases\Http\Init\Timeout;
use Appwrite\Platform\Modules\Databases\Services\Registry\Collections as CollectionsRegistry;
use Appwrite\Platform\Modules\Databases\Services\Registry\Databases as DatabasesRegistry;
use Appwrite\Platform\Modules\Databases\Services\Registry\DocumentsDB as DocumentsDBRegistry;
use Appwrite\Platform\Modules\Databases\Services\Registry\TablesDB as TablesDBRegistry;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(Timeout::getName(), new Timeout());

        foreach ([
            DatabasesRegistry::class,
            CollectionsRegistry::class,
            TablesDBRegistry::class,
            DocumentsDBRegistry::class
        ] as $registrar) {
            new $registrar($this);
        }
    }
}
