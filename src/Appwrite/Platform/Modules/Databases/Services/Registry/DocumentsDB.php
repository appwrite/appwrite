<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Create as CreateTable;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Delete as DeleteTable;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Attribute\Decrement as DecrementRowColumn;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Attribute\Increment as IncrementRowColumn;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Bulk\Delete as DeleteRows;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Bulk\Update as UpdateRows;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Bulk\Upsert as UpsertRows;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Create as CreateRow;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Delete as DeleteRow;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Get as GetRow;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Queries\Create as CreateDocumentsQuery;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Update as UpdateRow;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Upsert as UpsertRow;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\XList as ListRows;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Get as GetTable;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes\Create as CreateColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes\Delete as DeleteColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes\Get as GetColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes\XList as ListColumnIndexes;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Update as UpdateTable;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\XList as ListTables;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Create as CreateTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Delete as DeleteTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Get as GetTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Transactions\Create as CreateTransaction;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Transactions\Delete as DeleteTransaction;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Transactions\Get as GetTransaction;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Transactions\Operations\Create as CreateOperations;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Transactions\Update as UpdateTransaction;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Transactions\XList as ListTransactions;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Update as UpdateTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\XList as ListTablesDatabase;
use Utopia\Platform\Service;

class DocumentsDB extends Base
{
    protected function register(Service $service): void
    {
        $this->registerDatabaseActions($service);
        $this->registerTableActions($service);
        $this->registerIndexActions($service);
        $this->registerRowActions($service);
        $this->registerTransactionActions($service);
    }

    private function registerDatabaseActions(Service $service): void
    {
        $service->addAction('createDocumentsDBDatabase', CreateTablesDatabase::class);
        $service->addAction('getDocumentsDBDatabase', GetTablesDatabase::class);
        $service->addAction('updateDocumentsDBDatabase', UpdateTablesDatabase::class);
        $service->addAction('deleteDocumentsDBDatabase', DeleteTablesDatabase::class);
        $service->addAction('listDocumentsDBDatabases', ListTablesDatabase::class);
    }

    private function registerTableActions(Service $service): void
    {
        $service->addAction('createDocumentsDBCollection', CreateTable::class);
        $service->addAction('getDocumentsDBCollection', GetTable::class);
        $service->addAction('updateDocumentsDBCollection', UpdateTable::class);
        $service->addAction('deleteDocumentsDBCollection', DeleteTable::class);
        $service->addAction('listDocumentsDBCollections', ListTables::class);
    }

    private function registerIndexActions(Service $service): void
    {
        $service->addAction('createDocumentsDBIndex', CreateColumnIndex::class);
        $service->addAction('getDocumentsDBIndex', GetColumnIndex::class);
        $service->addAction('deleteDocumentsDBIndex', DeleteColumnIndex::class);
        $service->addAction('listDocumentsDBIndexes', ListColumnIndexes::class);
    }

    private function registerRowActions(Service $service): void
    {
        $service->addAction('createDocumentsDBDocument', CreateRow::class);
        $service->addAction('getDocumentsDBDocument', GetRow::class);
        $service->addAction('updateDocumentsDBDocument', UpdateRow::class);
        $service->addAction('updateDocumentsDBDocuments', UpdateRows::class);
        $service->addAction('upsertDocumentsDBDocument', UpsertRow::class);
        $service->addAction('upsertDocumentsDBDocuments', UpsertRows::class);
        $service->addAction('deleteDocumentsDBDocument', DeleteRow::class);
        $service->addAction('deleteDocumentsDBDocuments', DeleteRows::class);
        $service->addAction('listDocumentsDBDocuments', ListRows::class);
        $service->addAction('createDocumentsDBDocumentsQuery', CreateDocumentsQuery::class);
        $service->addAction('incrementDocumentsDBDocumentAttribute', IncrementRowColumn::class);
        $service->addAction('decrementDocumentsDBDocumentAttribute', DecrementRowColumn::class);
    }

    private function registerTransactionActions(Service $service): void
    {
        $service->addAction('createDocumentsDBTransaction', CreateTransaction::class);
        $service->addAction('getDocumentsDBTransaction', GetTransaction::class);
        $service->addAction('updateDocumentsDBTransaction', UpdateTransaction::class);
        $service->addAction('deleteDocumentsDBTransaction', DeleteTransaction::class);
        $service->addAction('listDocumentsDBTransactions', ListTransactions::class);
        $service->addAction('createDocumentsDBOperations', CreateOperations::class);
    }
}
