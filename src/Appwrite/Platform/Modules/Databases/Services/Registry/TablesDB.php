<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Appwrite\Platform\Modules\Databases\Http\TablesDB\Create as CreateTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Delete as DeleteTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Get as GetTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\BigInt\Create as CreateBigInt;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\BigInt\Update as UpdateBigInt;
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
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Longtext\Create as CreateLongtext;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Longtext\Update as UpdateLongtext;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Mediumtext\Create as CreateMediumtext;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Mediumtext\Update as UpdateMediumtext;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Point\Create as CreatePoint;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Point\Update as UpdatePoint;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Polygon\Create as CreatePolygon;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Polygon\Update as UpdatePolygon;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Relationship\Create as CreateRelationship;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Relationship\Update as UpdateRelationship;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\String\Create as CreateString;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\String\Update as UpdateString;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Text\Create as CreateText;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Text\Update as UpdateText;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\URL\Create as CreateURL;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\URL\Update as UpdateURL;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Varchar\Create as CreateVarchar;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Varchar\Update as UpdateVarchar;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\XList as ListColumns;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Create as CreateTable;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Delete as DeleteTable;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Get as GetTable;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Indexes\Create as CreateColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Indexes\Delete as DeleteColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Indexes\Get as GetColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Indexes\XList as ListColumnIndexes;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Bulk\Delete as DeleteRows;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Bulk\Update as UpdateRows;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Bulk\Upsert as UpsertRows;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Column\Decrement as DecrementRowColumn;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Column\Increment as IncrementRowColumn;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Create as CreateRow;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Delete as DeleteRow;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Get as GetRow;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Queries\Create as CreateRowsQuery;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Update as UpdateRow;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Upsert as UpsertRow;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\XList as ListRows;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Update as UpdateTable;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\XList as ListTables;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions\Create as CreateTransaction;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions\Delete as DeleteTransaction;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions\Get as GetTransaction;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions\Operations\Create as CreateOperations;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions\Update as UpdateTransaction;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Transactions\XList as ListTransactions;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Update as UpdateTablesDatabase;
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

    private function registerColumnActions(Service $service): void
    {
        // Column top level actions
        $service->addAction(GetColumn::getName(), GetColumn::class);
        $service->addAction(DeleteColumn::getName(), DeleteColumn::class);
        $service->addAction(ListColumns::getName(), ListColumns::class);

        // Column: Boolean
        $service->addAction(CreateBoolean::getName(), CreateBoolean::class);
        $service->addAction(UpdateBoolean::getName(), UpdateBoolean::class);

        // Column: Datetime
        $service->addAction(CreateDatetime::getName(), CreateDatetime::class);
        $service->addAction(UpdateDatetime::getName(), UpdateDatetime::class);

        // Column: Email
        $service->addAction(CreateEmail::getName(), CreateEmail::class);
        $service->addAction(UpdateEmail::getName(), UpdateEmail::class);

        // Column: Enum
        $service->addAction(CreateEnum::getName(), CreateEnum::class);
        $service->addAction(UpdateEnum::getName(), UpdateEnum::class);

        // Column: Float
        $service->addAction(CreateFloat::getName(), CreateFloat::class);
        $service->addAction(UpdateFloat::getName(), UpdateFloat::class);

        // Column: Integer
        $service->addAction(CreateInteger::getName(), CreateInteger::class);
        $service->addAction(UpdateInteger::getName(), UpdateInteger::class);

        // Column: BigInt
        $service->addAction(CreateBigInt::getName(), CreateBigInt::class);
        $service->addAction(UpdateBigInt::getName(), UpdateBigInt::class);

        // Column: IP
        $service->addAction(CreateIP::getName(), CreateIP::class);
        $service->addAction(UpdateIP::getName(), UpdateIP::class);

        // Column: Line
        $service->addAction(CreateLine::getName(), CreateLine::class);
        $service->addAction(UpdateLine::getName(), UpdateLine::class);

        // Column: Point
        $service->addAction(CreatePoint::getName(), CreatePoint::class);
        $service->addAction(UpdatePoint::getName(), UpdatePoint::class);

        // Column: Polygon
        $service->addAction(CreatePolygon::getName(), CreatePolygon::class);
        $service->addAction(UpdatePolygon::getName(), UpdatePolygon::class);

        // Column: Relationship
        $service->addAction(CreateRelationship::getName(), CreateRelationship::class);
        $service->addAction(UpdateRelationship::getName(), UpdateRelationship::class);

        // Column: String
        $service->addAction(CreateString::getName(), CreateString::class);
        $service->addAction(UpdateString::getName(), UpdateString::class);

        // Column: URL
        $service->addAction(CreateURL::getName(), CreateURL::class);
        $service->addAction(UpdateURL::getName(), UpdateURL::class);

        // Column: Varchar
        $service->addAction(CreateVarchar::getName(), CreateVarchar::class);
        $service->addAction(UpdateVarchar::getName(), UpdateVarchar::class);

        // Column: Text
        $service->addAction(CreateText::getName(), CreateText::class);
        $service->addAction(UpdateText::getName(), UpdateText::class);

        // Column: Mediumtext
        $service->addAction(CreateMediumtext::getName(), CreateMediumtext::class);
        $service->addAction(UpdateMediumtext::getName(), UpdateMediumtext::class);

        // Column: Longtext
        $service->addAction(CreateLongtext::getName(), CreateLongtext::class);
        $service->addAction(UpdateLongtext::getName(), UpdateLongtext::class);
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
        $service->addAction(CreateRowsQuery::getName(), CreateRowsQuery::class);
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
