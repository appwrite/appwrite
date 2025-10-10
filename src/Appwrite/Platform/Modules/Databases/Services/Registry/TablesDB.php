<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Appwrite\Platform\Modules\Databases\Http\TablesDB\Create as CreateTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Delete as DeleteTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Get as GetTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Boolean\Create as CreateBoolean;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Boolean\Update as UpdateBoolean;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Datetime\Create as CreateDatetime;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Datetime\Update as UpdateDatetime;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Delete as DeleteColumn;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Email\Create as CreateEmail;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Email\Update as UpdateEmail;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Enum\Create as CreateEnum;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Enum\Update as UpdateEnum;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Float\Create as CreateFloat;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Float\Update as UpdateFloat;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Get as GetColumn;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Integer\Create as CreateInteger;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Integer\Update as UpdateInteger;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\IP\Create as CreateIP;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\IP\Update as UpdateIP;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Line\Create as CreateLine;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Line\Update as UpdateLine;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Point\Create as CreatePoint;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Point\Update as UpdatePoint;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Polygon\Create as CreatePolygon;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Polygon\Update as UpdatePolygon;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Relationship\Create as CreateRelationship;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Relationship\Update as UpdateRelationship;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\String\Create as CreateString;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\String\Update as UpdateString;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\URL\Create as CreateURL;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\URL\Update as UpdateURL;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\XList as ListColumns;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Create as CreateTable;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Delete as DeleteTable;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Get as GetTable;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Indexes\Create as CreateColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Indexes\Delete as DeleteColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Indexes\Get as GetColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Indexes\XList as ListColumnIndexes;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Logs\XList as ListTableLogs;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Bulk\Delete as DeleteRows;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Bulk\Update as UpdateRows;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Bulk\Upsert as UpsertRows;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Column\Decrement as DecrementRowColumn;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Column\Increment as IncrementRowColumn;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Create as CreateRow;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Delete as DeleteRow;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Get as GetRow;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Logs\XList as ListRowLogs;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Update as UpdateRow;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Upsert as UpsertRow;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\XList as ListRows;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Update as UpdateTable;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Usage\Get as GetTableUsage;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\XList as ListTables;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions\Create as CreateTransaction;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions\Delete as DeleteTransaction;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions\Get as GetTransaction;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions\Operations\Create as CreateOperations;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions\Update as UpdateTransaction;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions\XList as ListTransactions;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Update as UpdateTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Usage\Get as GetTablesDatabaseUsage;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Usage\XList as ListTablesDatabaseUsage;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\XList as ListTablesDatabase;
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
class TablesDB extends Base
{
    protected function register(Service $service): void
    {
        $this->registerDatabaseActions($service);
        $this->registerTableActions($service);
        $this->registerColumnActions($service);
        $this->registerIndexActions($service);
        $this->registerRowActions($service);
        $this->registerTransactionActions($service);
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

    private function registerColumnActions(Service $service): void
    {
        // Column top level actions
        $service->addAction(GetColumn::getName(), new GetColumn());
        $service->addAction(DeleteColumn::getName(), new DeleteColumn());
        $service->addAction(ListColumns::getName(), new ListColumns());

        // Column: Boolean
        $service->addAction(CreateBoolean::getName(), new CreateBoolean());
        $service->addAction(UpdateBoolean::getName(), new UpdateBoolean());

        // Column: Datetime
        $service->addAction(CreateDatetime::getName(), new CreateDatetime());
        $service->addAction(UpdateDatetime::getName(), new UpdateDatetime());

        // Column: Email
        $service->addAction(CreateEmail::getName(), new CreateEmail());
        $service->addAction(UpdateEmail::getName(), new UpdateEmail());

        // Column: Enum
        $service->addAction(CreateEnum::getName(), new CreateEnum());
        $service->addAction(UpdateEnum::getName(), new UpdateEnum());

        // Column: Float
        $service->addAction(CreateFloat::getName(), new CreateFloat());
        $service->addAction(UpdateFloat::getName(), new UpdateFloat());

        // Column: Integer
        $service->addAction(CreateInteger::getName(), new CreateInteger());
        $service->addAction(UpdateInteger::getName(), new UpdateInteger());

        // Column: IP
        $service->addAction(CreateIP::getName(), new CreateIP());
        $service->addAction(UpdateIP::getName(), new UpdateIP());

        // Column: Line
        $service->addAction(CreateLine::getName(), new CreateLine());
        $service->addAction(UpdateLine::getName(), new UpdateLine());

        // Column: Point
        $service->addAction(CreatePoint::getName(), new CreatePoint());
        $service->addAction(UpdatePoint::getName(), new UpdatePoint());

        // Column: Polygon
        $service->addAction(CreatePolygon::getName(), new CreatePolygon());
        $service->addAction(UpdatePolygon::getName(), new UpdatePolygon());

        // Column: Relationship
        $service->addAction(CreateRelationship::getName(), new CreateRelationship());
        $service->addAction(UpdateRelationship::getName(), new UpdateRelationship());

        // Column: String
        $service->addAction(CreateString::getName(), new CreateString());
        $service->addAction(UpdateString::getName(), new UpdateString());

        // Column: URL
        $service->addAction(CreateURL::getName(), new CreateURL());
        $service->addAction(UpdateURL::getName(), new UpdateURL());
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

    private function registerTransactionActions(Service $service): void
    {
        $service->addAction(CreateTransaction::getName(), new CreateTransaction());
        $service->addAction(GetTransaction::getName(), new GetTransaction());
        $service->addAction(UpdateTransaction::getName(), new UpdateTransaction());
        $service->addAction(DeleteTransaction::getName(), new DeleteTransaction());
        $service->addAction(ListTransactions::getName(), new ListTransactions());
        $service->addAction(CreateOperations::getName(), new CreateOperations());
    }
}
