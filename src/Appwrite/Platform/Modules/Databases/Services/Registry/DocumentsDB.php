<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Create as CreateTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Delete as DeleteTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Get as GetTablesDatabase;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Boolean\Create as CreateBoolean;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Boolean\Update as UpdateBoolean;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Datetime\Create as CreateDatetime;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Datetime\Update as UpdateDatetime;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Delete as DeleteColumn;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Email\Create as CreateEmail;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Email\Update as UpdateEmail;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Enum\Create as CreateEnum;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Enum\Update as UpdateEnum;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Float\Create as CreateFloat;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Float\Update as UpdateFloat;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Get as GetColumn;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Integer\Create as CreateInteger;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Integer\Update as UpdateInteger;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\IP\Create as CreateIP;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\IP\Update as UpdateIP;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Line\Create as CreateLine;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Line\Update as UpdateLine;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Point\Create as CreatePoint;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Point\Update as UpdatePoint;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Polygon\Create as CreatePolygon;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Polygon\Update as UpdatePolygon;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Relationship\Create as CreateRelationship;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Relationship\Update as UpdateRelationship;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\String\Create as CreateString;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\String\Update as UpdateString;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\URL\Create as CreateURL;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\URL\Update as UpdateURL;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\XList as ListColumns;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Create as CreateTable;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Delete as DeleteTable;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Get as GetTable;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes\Create as CreateColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes\Delete as DeleteColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes\Get as GetColumnIndex;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Indexes\XList as ListColumnIndexes;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Logs\XList as ListTableLogs;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Bulk\Delete as DeleteRows;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Bulk\Update as UpdateRows;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Bulk\Upsert as UpsertRows;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Attribute\Decrement as DecrementRowColumn;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Attribute\Increment as IncrementRowColumn;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Create as CreateRow;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Delete as DeleteRow;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Get as GetRow;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Logs\XList as ListRowLogs;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Update as UpdateRow;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\Upsert as UpsertRow;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Documents\XList as ListRows;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Update as UpdateTable;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Usage\Get as GetTableUsage;
use Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\XList as ListTables;
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
        $this->registerColumnActions($service);
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

    private function registerColumnActions(Service $service): void
    {
        // Attribute top level actions
        $service->addAction(GetColumn::getName(), new GetColumn());
        $service->addAction(DeleteColumn::getName(), new DeleteColumn());
        $service->addAction(ListColumns::getName(), new ListColumns());

        // Attribute: Boolean
        $service->addAction(CreateBoolean::getName(), new CreateBoolean());
        $service->addAction(UpdateBoolean::getName(), new UpdateBoolean());

        // Attribute: Datetime
        $service->addAction(CreateDatetime::getName(), new CreateDatetime());
        $service->addAction(UpdateDatetime::getName(), new UpdateDatetime());

        // Attribute: Email
        $service->addAction(CreateEmail::getName(), new CreateEmail());
        $service->addAction(UpdateEmail::getName(), new UpdateEmail());

        // Attribute: Enum
        $service->addAction(CreateEnum::getName(), new CreateEnum());
        $service->addAction(UpdateEnum::getName(), new UpdateEnum());

        // Attribute: Float
        $service->addAction(CreateFloat::getName(), new CreateFloat());
        $service->addAction(UpdateFloat::getName(), new UpdateFloat());

        // Attribute: Integer
        $service->addAction(CreateInteger::getName(), new CreateInteger());
        $service->addAction(UpdateInteger::getName(), new UpdateInteger());

        // Attribute: IP
        $service->addAction(CreateIP::getName(), new CreateIP());
        $service->addAction(UpdateIP::getName(), new UpdateIP());

        // Attribute: Line
        $service->addAction(CreateLine::getName(), new CreateLine());
        $service->addAction(UpdateLine::getName(), new UpdateLine());

        // Attribute: Point
        $service->addAction(CreatePoint::getName(), new CreatePoint());
        $service->addAction(UpdatePoint::getName(), new UpdatePoint());

        // Attribute: Polygon
        $service->addAction(CreatePolygon::getName(), new CreatePolygon());
        $service->addAction(UpdatePolygon::getName(), new UpdatePolygon());

        // Attribute: Relationship
        $service->addAction(CreateRelationship::getName(), new CreateRelationship());
        $service->addAction(UpdateRelationship::getName(), new UpdateRelationship());

        // Attribute: String
        $service->addAction(CreateString::getName(), new CreateString());
        $service->addAction(UpdateString::getName(), new UpdateString());

        // Attribute: URL
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
}
