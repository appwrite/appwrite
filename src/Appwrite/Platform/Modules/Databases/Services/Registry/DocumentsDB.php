<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Create as CreateDocumentsDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Delete as DeleteDocumentsDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Get as GetDocumentsDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Update as UpdateDocumentsDatabase;
// TODO: usage endpoints
// use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Usage\Get as GetDocumentsDatabaseUsage;
// use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Usage\XList as ListDocumentsDatabaseUsage;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\XList as ListDocumentsDatabase;
use Utopia\Platform\Service;

/**
 * Registers all HTTP actions related to tables in the database module.
 *
 * This includes:
 * - Tables
 * - Rows
 * - Columns
 * - Column-Indexes
 */
class DocumentsDB extends Base
{
    protected function register(Service $service): void
    {
        $this->registerDatabaseActions($service);
    }

    private function registerDatabaseActions(Service $service): void
    {
        $service->addAction(CreateDocumentsDatabase::getName(), new CreateDocumentsDatabase());
        $service->addAction(GetDocumentsDatabase::getName(), new GetDocumentsDatabase());
        $service->addAction(UpdateDocumentsDatabase::getName(), new UpdateDocumentsDatabase());
        $service->addAction(DeleteDocumentsDatabase::getName(), new DeleteDocumentsDatabase());
        $service->addAction(ListDocumentsDatabase::getName(), new ListDocumentsDatabase());
        // TODO: usage endpoints
        // $service->addAction(GetDocumentsDatabaseUsage::getName(), new GetDocumentsDatabaseUsage());
        // $service->addAction(UpdateDocumentsDatabase::getName(), new UpdateDocumentsDatabase());
    }
}
