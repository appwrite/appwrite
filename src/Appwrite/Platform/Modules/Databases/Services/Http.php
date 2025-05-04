<?php

namespace Appwrite\Platform\Modules\Databases\Services;

use Appwrite\Platform\Modules\Databases\Http\Columns\Boolean\Create as CreateBoolean;
use Appwrite\Platform\Modules\Databases\Http\Columns\Boolean\Update as UpdateBoolean;
use Appwrite\Platform\Modules\Databases\Http\Columns\Datetime\Create as CreateDatetime;
use Appwrite\Platform\Modules\Databases\Http\Columns\Datetime\Update as UpdateDatetime;
use Appwrite\Platform\Modules\Databases\Http\Columns\Delete as DeleteColumn;
use Appwrite\Platform\Modules\Databases\Http\Columns\Email\Create as CreateEmail;
use Appwrite\Platform\Modules\Databases\Http\Columns\Email\Update as UpdateEmail;
use Appwrite\Platform\Modules\Databases\Http\Columns\Enum\Create as CreateEnum;
use Appwrite\Platform\Modules\Databases\Http\Columns\Enum\Update as UpdateEnum;
use Appwrite\Platform\Modules\Databases\Http\Columns\Float\Create as CreateFloat;
use Appwrite\Platform\Modules\Databases\Http\Columns\Float\Update as UpdateFloat;
use Appwrite\Platform\Modules\Databases\Http\Columns\Get as GetColumn;
use Appwrite\Platform\Modules\Databases\Http\Columns\Integer\Create as CreateInteger;
use Appwrite\Platform\Modules\Databases\Http\Columns\Integer\Update as UpdateInteger;
use Appwrite\Platform\Modules\Databases\Http\Columns\IP\Create as CreateIP;
use Appwrite\Platform\Modules\Databases\Http\Columns\IP\Update as UpdateIP;
use Appwrite\Platform\Modules\Databases\Http\Columns\Relationship\Create as CreateRelationship;
use Appwrite\Platform\Modules\Databases\Http\Columns\Relationship\Update as UpdateRelationship;
use Appwrite\Platform\Modules\Databases\Http\Columns\String\Create as CreateString;
use Appwrite\Platform\Modules\Databases\Http\Columns\String\Update as UpdateString;
use Appwrite\Platform\Modules\Databases\Http\Columns\URL\Create as CreateURL;
use Appwrite\Platform\Modules\Databases\Http\Columns\URL\Update as UpdateURL;
use Appwrite\Platform\Modules\Databases\Http\Columns\XList as ListColumns;
use Appwrite\Platform\Modules\Databases\Http\Databases\Create as CreateDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Delete as DeleteDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Get as GetDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Logs\XList as ListDatabaseLogs;
use Appwrite\Platform\Modules\Databases\Http\Databases\Update as UpdateDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\XList as ListDatabases;
use Appwrite\Platform\Modules\Databases\Http\Indexes\Create as CreateIndex;
use Appwrite\Platform\Modules\Databases\Http\Indexes\Delete as DeleteIndex;
use Appwrite\Platform\Modules\Databases\Http\Indexes\Get as GetIndex;
use Appwrite\Platform\Modules\Databases\Http\Indexes\XList as ListIndexes;
use Appwrite\Platform\Modules\Databases\Http\Tables\Create as CreateTable;
use Appwrite\Platform\Modules\Databases\Http\Tables\Delete as DeleteTable;
use Appwrite\Platform\Modules\Databases\Http\Tables\Get as GetTable;
use Appwrite\Platform\Modules\Databases\Http\Tables\Logs\XList as ListTableLogs;
use Appwrite\Platform\Modules\Databases\Http\Tables\Update as UpdateTable;
use Appwrite\Platform\Modules\Databases\Http\Tables\XList as ListTables;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->registerDatabaseActions();
        $this->registerTableActions();
        $this->registerColumnActions();
        $this->registerIndexActions();
        $this->registerRowActions();
    }

    private function registerDatabaseActions(): void
    {
        $this->addAction(CreateDatabase::getName(), new CreateDatabase());
        $this->addAction(GetDatabase::getName(), new GetDatabase());
        $this->addAction(UpdateDatabase::getName(), new UpdateDatabase());
        $this->addAction(DeleteDatabase::getName(), new DeleteDatabase());
        $this->addAction(ListDatabases::getName(), new ListDatabases());
        $this->addAction(ListDatabaseLogs::getName(), new ListDatabaseLogs());
    }

    private function registerTableActions(): void
    {
        $this->addAction(CreateTable::getName(), new CreateTable());
        $this->addAction(GetTable::getName(), new GetTable());
        $this->addAction(UpdateTable::getName(), new UpdateTable());
        $this->addAction(DeleteTable::getName(), new DeleteTable());
        $this->addAction(ListTables::getName(), new ListTables());
        $this->addAction(ListTableLogs::getName(), new ListTableLogs());
    }

    private function registerColumnActions(): void
    {
        // Column top level actions
        $this->addAction(GetColumn::getName(), new GetColumn());
        $this->addAction(DeleteColumn::getName(), new DeleteColumn());
        $this->addAction(ListColumns::getName(), new ListColumns());

        // Column: Boolean
        $this->addAction(CreateBoolean::getName(), new CreateBoolean());
        $this->addAction(UpdateBoolean::getName(), new UpdateBoolean());

        // Column: Datetime
        $this->addAction(CreateDatetime::getName(), new CreateDatetime());
        $this->addAction(UpdateDatetime::getName(), new UpdateDatetime());

        // Column: Email
        $this->addAction(CreateEmail::getName(), new CreateEmail());
        $this->addAction(UpdateEmail::getName(), new UpdateEmail());

        // Column: Enum
        $this->addAction(CreateEnum::getName(), new CreateEnum());
        $this->addAction(UpdateEnum::getName(), new UpdateEnum());

        // Column: Float
        $this->addAction(CreateFloat::getName(), new CreateFloat());
        $this->addAction(UpdateFloat::getName(), new UpdateFloat());

        // Column: Integer
        $this->addAction(CreateInteger::getName(), new CreateInteger());
        $this->addAction(UpdateInteger::getName(), new UpdateInteger());

        // Column: IP
        $this->addAction(CreateIP::getName(), new CreateIP());
        $this->addAction(UpdateIP::getName(), new UpdateIP());

        // Column: Relationship
        $this->addAction(CreateRelationship::getName(), new CreateRelationship());
        $this->addAction(UpdateRelationship::getName(), new UpdateRelationship());

        // Column: String
        $this->addAction(CreateString::getName(), new CreateString());
        $this->addAction(UpdateString::getName(), new UpdateString());

        // Column: URL
        $this->addAction(CreateURL::getName(), new CreateURL());
        $this->addAction(UpdateURL::getName(), new UpdateURL());
    }

    private function registerIndexActions(): void
    {
        $this->addAction(CreateIndex::getName(), new CreateIndex());
        $this->addAction(GetIndex::getName(), new GetIndex());
        $this->addAction(DeleteIndex::getName(), new DeleteIndex());
        $this->addAction(ListIndexes::getName(), new ListIndexes());
    }

    private function registerRowActions(): void
    {

    }
}
