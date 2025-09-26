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
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Logs\XList as ListRowLogs;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Update as UpdateRow;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Upsert as UpsertRow;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\XList as ListRows;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Get as GetTable;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes\Create as CreateColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes\Delete as DeleteColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes\Get as GetColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes\XList as ListColumnIndexes;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Logs\XList as ListTableLogs;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Update as UpdateTable;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Usage\Get as GetTableUsage;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\XList as ListTables;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Create as CreateTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Delete as DeleteTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Get as GetTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Update as UpdateTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Usage\Get as GetTablesDatabaseUsage;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Usage\XList as ListTablesDatabaseUsage;
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
    }

    private function registerDatabaseActions(Service $service): void
    {
        $service->addAction(CreateTablesDatabase::getName(), new CreateTablesDatabase());
        $service->addAction(GetTablesDatabase::getName(), new GetTablesDatabase());
        $service->addAction(UpdateTablesDatabase::getName(), new UpdateTablesDatabase());
        $service->addAction(DeleteTablesDatabase::getName(), new DeleteTablesDatabase());
        $service->addAction(ListTablesDatabase::getName(), new ListTablesDatabase());
        $service->addAction(GetTablesDatabaseUsage::getName(), new GetTablesDatabaseUsage());
        $service->addAction(ListTablesDatabaseUsage::getName(), new ListTablesDatabaseUsage());
    }

    private function registerTableActions(Service $service): void
    {
        $service->addAction(CreateTable::getName(), new CreateTable());
        $service->addAction(GetTable::getName(), new GetTable());
        $service->addAction(UpdateTable::getName(), new UpdateTable());
        $service->addAction(DeleteTable::getName(), new DeleteTable());
        $service->addAction(ListTables::getName(), new ListTables());
        $service->addAction(ListTableLogs::getName(), new ListTableLogs());
        $service->addAction(GetTableUsage::getName(), new GetTableUsage());
    }

    private function registerIndexActions(Service $service): void
    {
        $service->addAction(CreateColumnIndex::getName(), new CreateColumnIndex());
        $service->addAction(GetColumnIndex::getName(), new GetColumnIndex());
        $service->addAction(DeleteColumnIndex::getName(), new DeleteColumnIndex());
        $service->addAction(ListColumnIndexes::getName(), new ListColumnIndexes());
    }

    private function registerRowActions(Service $service): void
    {
        $service->addAction(CreateRow::getName(), new CreateRow());
        $service->addAction(GetRow::getName(), new GetRow());
        $service->addAction(UpdateRow::getName(), new UpdateRow());
        $service->addAction(UpdateRows::getName(), new UpdateRows());
        $service->addAction(UpsertRow::getName(), new UpsertRow());
        $service->addAction(UpsertRows::getName(), new UpsertRows());
        $service->addAction(DeleteRow::getName(), new DeleteRow());
        $service->addAction(DeleteRows::getName(), new DeleteRows());
        $service->addAction(ListRows::getName(), new ListRows());
        $service->addAction(ListRowLogs::getName(), new ListRowLogs());
        $service->addAction(IncrementRowColumn::getName(), new IncrementRowColumn());
        $service->addAction(DecrementRowColumn::getName(), new DecrementRowColumn());
    }
}
