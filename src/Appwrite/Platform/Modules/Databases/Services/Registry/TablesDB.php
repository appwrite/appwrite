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
        $service->addAction('createTablesDatabase', CreateTablesDatabase::class);
        $service->addAction('getTablesDatabase', GetTablesDatabase::class);
        $service->addAction('updateTablesDatabase', UpdateTablesDatabase::class);
        $service->addAction('deleteTablesDatabase', DeleteTablesDatabase::class);
        $service->addAction('listTablesDatabases', ListTablesDatabase::class);
    }

    private function registerTableActions(Service $service): void
    {
        $service->addAction('createTable', CreateTable::class);
        $service->addAction('getTable', GetTable::class);
        $service->addAction('updateTable', UpdateTable::class);
        $service->addAction('deleteTable', DeleteTable::class);
        $service->addAction('listTables', ListTables::class);
    }

    private function registerColumnActions(Service $service): void
    {
        // Column top level actions
        $service->addAction('getColumn', GetColumn::class);
        $service->addAction('deleteColumn', DeleteColumn::class);
        $service->addAction('listColumns', ListColumns::class);

        // Column: Boolean
        $service->addAction('createBooleanColumn', CreateBoolean::class);
        $service->addAction('updateBooleanColumn', UpdateBoolean::class);

        // Column: Datetime
        $service->addAction('createDatetimeColumn', CreateDatetime::class);
        $service->addAction('updateDatetimeColumn', UpdateDatetime::class);

        // Column: Email
        $service->addAction('createEmailColumn', CreateEmail::class);
        $service->addAction('updateEmailColumn', UpdateEmail::class);

        // Column: Enum
        $service->addAction('createEnumColumn', CreateEnum::class);
        $service->addAction('updateEnumColumn', UpdateEnum::class);

        // Column: Float
        $service->addAction('createFloatColumn', CreateFloat::class);
        $service->addAction('updateFloatColumn', UpdateFloat::class);

        // Column: Integer
        $service->addAction('createIntegerColumn', CreateInteger::class);
        $service->addAction('updateIntegerColumn', UpdateInteger::class);

        // Column: BigInt
        $service->addAction('createBigIntColumn', CreateBigInt::class);
        $service->addAction('updateBigIntColumn', UpdateBigInt::class);

        // Column: IP
        $service->addAction('createIpColumn', CreateIP::class);
        $service->addAction('updateIpColumn', UpdateIP::class);

        // Column: Line
        $service->addAction('createLineColumn', CreateLine::class);
        $service->addAction('updateLineColumn', UpdateLine::class);

        // Column: Point
        $service->addAction('createPointColumn', CreatePoint::class);
        $service->addAction('updatePointColumn', UpdatePoint::class);

        // Column: Polygon
        $service->addAction('createPolygonColumn', CreatePolygon::class);
        $service->addAction('updatePolygonColumn', UpdatePolygon::class);

        // Column: Relationship
        $service->addAction('createRelationshipColumn', CreateRelationship::class);
        $service->addAction('updateRelationshipColumn', UpdateRelationship::class);

        // Column: String
        $service->addAction('createStringColumn', CreateString::class);
        $service->addAction('updateStringColumn', UpdateString::class);

        // Column: URL
        $service->addAction('createUrlColumn', CreateURL::class);
        $service->addAction('updateUrlColumn', UpdateURL::class);

        // Column: Varchar
        $service->addAction('createVarcharColumn', CreateVarchar::class);
        $service->addAction('updateVarcharColumn', UpdateVarchar::class);

        // Column: Text
        $service->addAction('createTextColumn', CreateText::class);
        $service->addAction('updateTextColumn', UpdateText::class);

        // Column: Mediumtext
        $service->addAction('createMediumtextColumn', CreateMediumtext::class);
        $service->addAction('updateMediumtextColumn', UpdateMediumtext::class);

        // Column: Longtext
        $service->addAction('createLongtextColumn', CreateLongtext::class);
        $service->addAction('updateLongtextColumn', UpdateLongtext::class);
    }

    private function registerIndexActions(Service $service): void
    {
        $service->addAction('createColumnIndex', CreateColumnIndex::class);
        $service->addAction('getColumnIndex', GetColumnIndex::class);
        $service->addAction('deleteColumnIndex', DeleteColumnIndex::class);
        $service->addAction('listColumnIndexes', ListColumnIndexes::class);
    }

    private function registerRowActions(Service $service): void
    {
        $service->addAction('createRow', CreateRow::class);
        $service->addAction('getRow', GetRow::class);
        $service->addAction('updateRow', UpdateRow::class);
        $service->addAction('updateRows', UpdateRows::class);
        $service->addAction('upsertRow', UpsertRow::class);
        $service->addAction('upsertRows', UpsertRows::class);
        $service->addAction('deleteRow', DeleteRow::class);
        $service->addAction('deleteRows', DeleteRows::class);
        $service->addAction('listRows', ListRows::class);
        $service->addAction('createRowsQuery', CreateRowsQuery::class);
        $service->addAction('incrementRowColumn', IncrementRowColumn::class);
        $service->addAction('decrementRowColumn', DecrementRowColumn::class);
    }

    private function registerTransactionActions(Service $service): void
    {
        $service->addAction('createTransaction', CreateTransaction::class);
        $service->addAction('getTransaction', GetTransaction::class);
        $service->addAction('updateTransaction', UpdateTransaction::class);
        $service->addAction('deleteTransaction', DeleteTransaction::class);
        $service->addAction('listTransactions', ListTransactions::class);
        $service->addAction('createOperations', CreateOperations::class);
    }
}
