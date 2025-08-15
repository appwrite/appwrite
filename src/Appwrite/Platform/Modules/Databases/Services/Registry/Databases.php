<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Appwrite\Platform\Modules\Databases\Http\Databases\Create as CreateDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Delete as DeleteDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Get as GetDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Logs\XList as ListDatabaseLogs;
use Appwrite\Platform\Modules\Databases\Http\Databases\Update as UpdateDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Usage\Get as GetDatabaseUsage;
use Appwrite\Platform\Modules\Databases\Http\Databases\Usage\XList as ListDatabaseUsage;
use Appwrite\Platform\Modules\Databases\Http\Databases\XList as ListDatabases;
use Utopia\Platform\Service;

/**
 * Registers all HTTP actions related to database in the module.
 */
class Databases extends Base
{
    public function register(Service $service): void
    {
        $service->addAction(CreateDatabase::getName(), new CreateDatabase());
        $service->addAction(GetDatabase::getName(), new GetDatabase());
        $service->addAction(UpdateDatabase::getName(), new UpdateDatabase());
        $service->addAction(DeleteDatabase::getName(), new DeleteDatabase());
        $service->addAction(ListDatabases::getName(), new ListDatabases());
        $service->addAction(ListDatabaseLogs::getName(), new ListDatabaseLogs());
        $service->addAction(GetDatabaseUsage::getName(), new GetDatabaseUsage());
        $service->addAction(ListDatabaseUsage::getName(), new ListDatabaseUsage());
    }
}
