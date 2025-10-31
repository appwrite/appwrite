<?php

namespace Appwrite\Platform\Modules\Databases\Services;

use Appwrite\Platform\Modules\Databases\Http\Init\Timeout;
use Appwrite\Platform\Modules\Databases\Services\Registry\DocumentsDB as DocumentsDBRegistry;
use Appwrite\Platform\Modules\Databases\Services\Registry\Legacy as LegacyRegistry;
use Appwrite\Platform\Modules\Databases\Services\Registry\TablesDB as TablesDBDBRegistry;
use Appwrite\Platform\Modules\Databases\Services\Registry\VectorDB as VectorDBRegistry;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(Timeout::getName(), new Timeout());

        foreach ([
            LegacyRegistry::class,
            TablesDBDBRegistry::class,
            DocumentsDBRegistry::class,
            VectorDBRegistry::class
        ] as $registrar) {
            new $registrar($this);
        }
    }
}
