<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Boolean\Create as CreateBooleanAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Boolean\Update as UpdateBooleanAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Datetime\Create as CreateDatetimeAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Datetime\Update as UpdateDatetimeAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Delete as DeleteAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Email\Create as CreateEmailAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Email\Update as UpdateEmailAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Enum\Create as CreateEnumAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Enum\Update as UpdateEnumAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Float\Create as CreateFloatAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Float\Update as UpdateFloatAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Get as GetAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Integer\Create as CreateIntegerAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Integer\Update as UpdateIntegerAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\IP\Create as CreateIPAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\IP\Update as UpdateIPAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Line\Create as CreateLineAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Line\Update as UpdateLineAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Point\Create as CreatePointAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Point\Update as UpdatePointAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Polygon\Create as CreatePolygonAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Polygon\Update as UpdatePolygonAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Relationship\Create as CreateRelationshipAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Relationship\Update as UpdateRelationshipAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\String\Create as CreateStringAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\String\Update as UpdateStringAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\URL\Create as CreateURLAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\URL\Update as UpdateURLAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\XList as ListAttributes;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Create as CreateCollection;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Delete as DeleteCollection;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Attribute\Decrement as DecrementDocumentAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Attribute\Increment as IncrementDocumentAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Bulk\Delete as DeleteDocuments;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Bulk\Update as UpdateDocuments;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Bulk\Upsert as UpsertDocuments;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Create as CreateDocument;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Delete as DeleteDocument;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Get as GetDocument;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Logs\XList as ListDocumentLogs;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Update as UpdateDocument;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Upsert as UpsertDocument;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\XList as ListDocuments;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Get as GetCollection;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\Create as CreateIndex;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\Delete as DeleteIndex;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\Get as GetIndex;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\XList as ListIndexes;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Logs\XList as ListCollectionLogs;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Update as UpdateCollection;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Usage\Get as GetCollectionUsage;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\XList as ListCollections;
use Appwrite\Platform\Modules\Databases\Http\Databases\Create as CreateDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Delete as DeleteDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Get as GetDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Logs\XList as ListDatabaseLogs;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Create as CreateTransaction;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Delete as DeleteTransaction;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Get as GetTransaction;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Operations\Create as CreateOperations;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Update as UpdateTransaction;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\XList as ListTransactions;
use Appwrite\Platform\Modules\Databases\Http\Databases\Update as UpdateDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Usage\Get as GetDatabaseUsage;
use Appwrite\Platform\Modules\Databases\Http\Databases\Usage\XList as ListDatabaseUsage;
use Appwrite\Platform\Modules\Databases\Http\Databases\XList as ListDatabases;
use Utopia\Platform\Service;

/**
 * Registers all HTTP actions related to collections in the database module.
 *
 * This includes:
 * - Collections
 * - Documents
 * - Attributes
 * - Indexes
 * - Transactions
 */
class Legacy extends Base
{
    protected function register(Service $service): void
    {
        $this->registerDatabaseActions($service);
        $this->registerCollectionActions($service);
        $this->registerDocumentActions($service);
        $this->registerAttributeActions($service);
        $this->registerIndexActions($service);
        $this->registerTransactionActions($service);
    }

    public function registerDatabaseActions(Service $service): void
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

    private function registerCollectionActions(Service $service): void
    {
        $service->addAction(CreateCollection::getName(), new CreateCollection());
        $service->addAction(GetCollection::getName(), new GetCollection());
        $service->addAction(UpdateCollection::getName(), new UpdateCollection());
        $service->addAction(DeleteCollection::getName(), new DeleteCollection());
        $service->addAction(ListCollections::getName(), new ListCollections());
        $service->addAction(ListCollectionLogs::getName(), new ListCollectionLogs());
        $service->addAction(GetCollectionUsage::getName(), new GetCollectionUsage());
    }

    private function registerDocumentActions(Service $service): void
    {
        $service->addAction(CreateDocument::getName(), new CreateDocument());
        $service->addAction(GetDocument::getName(), new GetDocument());
        $service->addAction(UpdateDocument::getName(), new UpdateDocument());
        $service->addAction(UpdateDocuments::getName(), new UpdateDocuments());
        $service->addAction(UpsertDocument::getName(), new UpsertDocument());
        $service->addAction(UpsertDocuments::getName(), new UpsertDocuments());
        $service->addAction(DeleteDocument::getName(), new DeleteDocument());
        $service->addAction(DeleteDocuments::getName(), new DeleteDocuments());
        $service->addAction(ListDocuments::getName(), new ListDocuments());
        $service->addAction(ListDocumentLogs::getName(), new ListDocumentLogs());
        $service->addAction(IncrementDocumentAttribute::getName(), new IncrementDocumentAttribute());
        $service->addAction(DecrementDocumentAttribute::getName(), new DecrementDocumentAttribute());

    }

    private function registerAttributeActions(Service $service): void
    {
        // Attribute top-level actions
        $service->addAction(GetAttribute::getName(), new GetAttribute());
        $service->addAction(DeleteAttribute::getName(), new DeleteAttribute());
        $service->addAction(ListAttributes::getName(), new ListAttributes());

        // Attribute: Boolean
        $service->addAction(CreateBooleanAttribute::getName(), new CreateBooleanAttribute());
        $service->addAction(UpdateBooleanAttribute::getName(), new UpdateBooleanAttribute());

        // Attribute: Datetime
        $service->addAction(CreateDatetimeAttribute::getName(), new CreateDatetimeAttribute());
        $service->addAction(UpdateDatetimeAttribute::getName(), new UpdateDatetimeAttribute());

        // Attribute: Email
        $service->addAction(CreateEmailAttribute::getName(), new CreateEmailAttribute());
        $service->addAction(UpdateEmailAttribute::getName(), new UpdateEmailAttribute());

        // Attribute: Enum
        $service->addAction(CreateEnumAttribute::getName(), new CreateEnumAttribute());
        $service->addAction(UpdateEnumAttribute::getName(), new UpdateEnumAttribute());

        // Attribute: Float
        $service->addAction(CreateFloatAttribute::getName(), new CreateFloatAttribute());
        $service->addAction(UpdateFloatAttribute::getName(), new UpdateFloatAttribute());

        // Attribute: Integer
        $service->addAction(CreateIntegerAttribute::getName(), new CreateIntegerAttribute());
        $service->addAction(UpdateIntegerAttribute::getName(), new UpdateIntegerAttribute());

        // Attribute: IP
        $service->addAction(CreateIPAttribute::getName(), new CreateIPAttribute());
        $service->addAction(UpdateIPAttribute::getName(), new UpdateIPAttribute());

        // Attribute: Line
        $service->addAction(CreateLineAttribute::getName(), new CreateLineAttribute());
        $service->addAction(UpdateLineAttribute::getName(), new UpdateLineAttribute());

        // Attribute: Point
        $service->addAction(CreatePointAttribute::getName(), new CreatePointAttribute());
        $service->addAction(UpdatePointAttribute::getName(), new UpdatePointAttribute());

        // Attribute: Polygon
        $service->addAction(CreatePolygonAttribute::getName(), new CreatePolygonAttribute());
        $service->addAction(UpdatePolygonAttribute::getName(), new UpdatePolygonAttribute());

        // Attribute: Relationship
        $service->addAction(CreateRelationshipAttribute::getName(), new CreateRelationshipAttribute());
        $service->addAction(UpdateRelationshipAttribute::getName(), new UpdateRelationshipAttribute());

        // Attribute: String
        $service->addAction(CreateStringAttribute::getName(), new CreateStringAttribute());
        $service->addAction(UpdateStringAttribute::getName(), new UpdateStringAttribute());

        // Attribute: URL
        $service->addAction(CreateURLAttribute::getName(), new CreateURLAttribute());
        $service->addAction(UpdateURLAttribute::getName(), new UpdateURLAttribute());
    }

    private function registerIndexActions(Service $service): void
    {
        $service->addAction(CreateIndex::getName(), new CreateIndex());
        $service->addAction(GetIndex::getName(), new GetIndex());
        $service->addAction(DeleteIndex::getName(), new DeleteIndex());
        $service->addAction(ListIndexes::getName(), new ListIndexes());
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
