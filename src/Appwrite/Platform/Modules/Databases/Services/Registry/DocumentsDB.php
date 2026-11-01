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
        $service->addAction(CreateTablesDatabase::getName(), CreateTablesDatabase::class);
        $service->addAction(GetTablesDatabase::getName(), GetTablesDatabase::class);
        $service->addAction(UpdateTablesDatabase::getName(), UpdateTablesDatabase::class);
        $service->addAction(DeleteTablesDatabase::getName(), DeleteTablesDatabase::class);
        $service->addAction(ListTablesDatabase::getName(), ListTablesDatabase::class);
    }

    private function registerTableActions(Service $service): void
    {
        $service->addAction(CreateTable::getName(), CreateTable::class);
        $service->addAction(GetTable::getName(), GetTable::class);
        $service->addAction(UpdateTable::getName(), UpdateTable::class);
        $service->addAction(DeleteTable::getName(), DeleteTable::class);
        $service->addAction(ListTables::getName(), ListTables::class);
    }

    private function registerIndexActions(Service $service): void
    {
        $service->addAction(CreateColumnIndex::getName(), CreateColumnIndex::class);
        $service->addAction(GetColumnIndex::getName(), GetColumnIndex::class);
        $service->addAction(DeleteColumnIndex::getName(), DeleteColumnIndex::class);
        $service->addAction(ListColumnIndexes::getName(), ListColumnIndexes::class);
    }

    private function registerRowActions(Service $service): void
    {
        $service->addAction(CreateRow::getName(), CreateRow::class);
        $service->addAction(GetRow::getName(), GetRow::class);
        $service->addAction(UpdateRow::getName(), UpdateRow::class);
        $service->addAction(UpdateRows::getName(), UpdateRows::class);
        $service->addAction(UpsertRow::getName(), UpsertRow::class);
        $service->addAction(UpsertRows::getName(), UpsertRows::class);
        $service->addAction(DeleteRow::getName(), DeleteRow::class);
        $service->addAction(DeleteRows::getName(), DeleteRows::class);
        $service->addAction(ListRows::getName(), ListRows::class);
        $service->addAction(CreateDocumentsQuery::getName(), CreateDocumentsQuery::class);
        $service->addAction(IncrementRowColumn::getName(), IncrementRowColumn::class);
        $service->addAction(DecrementRowColumn::getName(), DecrementRowColumn::class);
    }

    private function registerTransactionActions(Service $service): void
    {
        $service->addAction(CreateTransaction::getName(), CreateTransaction::class);
        $service->addAction(GetTransaction::getName(), GetTransaction::class);
        $service->addAction(UpdateTransaction::getName(), UpdateTransaction::class);
        $service->addAction(DeleteTransaction::getName(), DeleteTransaction::class);
        $service->addAction(ListTransactions::getName(), ListTransactions::class);
        $service->addAction(CreateOperations::getName(), CreateOperations::class);
    }
}
