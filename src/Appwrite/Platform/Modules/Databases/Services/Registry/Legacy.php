<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\BigInt\Create as CreateBigIntAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\BigInt\Update as UpdateBigIntAttribute;
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
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Longtext\Create as CreateLongtextAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Longtext\Update as UpdateLongtextAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Mediumtext\Create as CreateMediumtextAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Mediumtext\Update as UpdateMediumtextAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Point\Create as CreatePointAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Point\Update as UpdatePointAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Polygon\Create as CreatePolygonAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Polygon\Update as UpdatePolygonAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Relationship\Create as CreateRelationshipAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Relationship\Update as UpdateRelationshipAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\String\Create as CreateStringAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\String\Update as UpdateStringAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Text\Create as CreateTextAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Text\Update as UpdateTextAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\URL\Create as CreateURLAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\URL\Update as UpdateURLAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Varchar\Create as CreateVarcharAttribute;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Varchar\Update as UpdateVarcharAttribute;
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
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Queries\Create as CreateDocumentsQuery;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Update as UpdateDocument;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Upsert as UpsertDocument;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\XList as ListDocuments;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Get as GetCollection;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\Create as CreateIndex;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\Delete as DeleteIndex;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\Get as GetIndex;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\XList as ListIndexes;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Update as UpdateCollection;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\XList as ListCollections;
use Appwrite\Platform\Modules\Databases\Http\Databases\Create as CreateDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Delete as DeleteDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Get as GetDatabase;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Create as CreateTransaction;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Delete as DeleteTransaction;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Get as GetTransaction;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Operations\Create as CreateOperations;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\Update as UpdateTransaction;
use Appwrite\Platform\Modules\Databases\Http\Databases\Transactions\XList as ListTransactions;
use Appwrite\Platform\Modules\Databases\Http\Databases\Update as UpdateDatabase;
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
        $service->addAction(CreateDatabase::getName(), CreateDatabase::class);
        $service->addAction(GetDatabase::getName(), GetDatabase::class);
        $service->addAction(UpdateDatabase::getName(), UpdateDatabase::class);
        $service->addAction(DeleteDatabase::getName(), DeleteDatabase::class);
        $service->addAction(ListDatabases::getName(), ListDatabases::class);
    }

    private function registerCollectionActions(Service $service): void
    {
        $service->addAction(CreateCollection::getName(), CreateCollection::class);
        $service->addAction(GetCollection::getName(), GetCollection::class);
        $service->addAction(UpdateCollection::getName(), UpdateCollection::class);
        $service->addAction(DeleteCollection::getName(), DeleteCollection::class);
        $service->addAction(ListCollections::getName(), ListCollections::class);
    }

    private function registerDocumentActions(Service $service): void
    {
        $service->addAction(CreateDocument::getName(), CreateDocument::class);
        $service->addAction(GetDocument::getName(), GetDocument::class);
        $service->addAction(UpdateDocument::getName(), UpdateDocument::class);
        $service->addAction(UpdateDocuments::getName(), UpdateDocuments::class);
        $service->addAction(UpsertDocument::getName(), UpsertDocument::class);
        $service->addAction(UpsertDocuments::getName(), UpsertDocuments::class);
        $service->addAction(DeleteDocument::getName(), DeleteDocument::class);
        $service->addAction(DeleteDocuments::getName(), DeleteDocuments::class);
        $service->addAction(ListDocuments::getName(), ListDocuments::class);
        $service->addAction(CreateDocumentsQuery::getName(), CreateDocumentsQuery::class);
        $service->addAction(IncrementDocumentAttribute::getName(), IncrementDocumentAttribute::class);
        $service->addAction(DecrementDocumentAttribute::getName(), DecrementDocumentAttribute::class);

    }

    private function registerAttributeActions(Service $service): void
    {
        // Attribute top-level actions
        $service->addAction(GetAttribute::getName(), GetAttribute::class);
        $service->addAction(DeleteAttribute::getName(), DeleteAttribute::class);
        $service->addAction(ListAttributes::getName(), ListAttributes::class);

        // Attribute: Boolean
        $service->addAction(CreateBooleanAttribute::getName(), CreateBooleanAttribute::class);
        $service->addAction(UpdateBooleanAttribute::getName(), UpdateBooleanAttribute::class);

        // Attribute: Datetime
        $service->addAction(CreateDatetimeAttribute::getName(), CreateDatetimeAttribute::class);
        $service->addAction(UpdateDatetimeAttribute::getName(), UpdateDatetimeAttribute::class);

        // Attribute: Email
        $service->addAction(CreateEmailAttribute::getName(), CreateEmailAttribute::class);
        $service->addAction(UpdateEmailAttribute::getName(), UpdateEmailAttribute::class);

        // Attribute: Enum
        $service->addAction(CreateEnumAttribute::getName(), CreateEnumAttribute::class);
        $service->addAction(UpdateEnumAttribute::getName(), UpdateEnumAttribute::class);

        // Attribute: Float
        $service->addAction(CreateFloatAttribute::getName(), CreateFloatAttribute::class);
        $service->addAction(UpdateFloatAttribute::getName(), UpdateFloatAttribute::class);

        // Attribute: Integer
        $service->addAction(CreateIntegerAttribute::getName(), CreateIntegerAttribute::class);
        $service->addAction(UpdateIntegerAttribute::getName(), UpdateIntegerAttribute::class);

        // Attribute: BigInt
        $service->addAction(CreateBigIntAttribute::getName(), CreateBigIntAttribute::class);
        $service->addAction(UpdateBigIntAttribute::getName(), UpdateBigIntAttribute::class);

        // Attribute: IP
        $service->addAction(CreateIPAttribute::getName(), CreateIPAttribute::class);
        $service->addAction(UpdateIPAttribute::getName(), UpdateIPAttribute::class);

        // Attribute: Line
        $service->addAction(CreateLineAttribute::getName(), CreateLineAttribute::class);
        $service->addAction(UpdateLineAttribute::getName(), UpdateLineAttribute::class);

        // Attribute: Point
        $service->addAction(CreatePointAttribute::getName(), CreatePointAttribute::class);
        $service->addAction(UpdatePointAttribute::getName(), UpdatePointAttribute::class);

        // Attribute: Polygon
        $service->addAction(CreatePolygonAttribute::getName(), CreatePolygonAttribute::class);
        $service->addAction(UpdatePolygonAttribute::getName(), UpdatePolygonAttribute::class);

        // Attribute: Relationship
        $service->addAction(CreateRelationshipAttribute::getName(), CreateRelationshipAttribute::class);
        $service->addAction(UpdateRelationshipAttribute::getName(), UpdateRelationshipAttribute::class);

        // Attribute: String
        $service->addAction(CreateStringAttribute::getName(), CreateStringAttribute::class);
        $service->addAction(UpdateStringAttribute::getName(), UpdateStringAttribute::class);

        // Attribute: URL
        $service->addAction(CreateURLAttribute::getName(), CreateURLAttribute::class);
        $service->addAction(UpdateURLAttribute::getName(), UpdateURLAttribute::class);

        // Attribute: Varchar
        $service->addAction(CreateVarcharAttribute::getName(), CreateVarcharAttribute::class);
        $service->addAction(UpdateVarcharAttribute::getName(), UpdateVarcharAttribute::class);

        // Attribute: Text
        $service->addAction(CreateTextAttribute::getName(), CreateTextAttribute::class);
        $service->addAction(UpdateTextAttribute::getName(), UpdateTextAttribute::class);

        // Attribute: Mediumtext
        $service->addAction(CreateMediumtextAttribute::getName(), CreateMediumtextAttribute::class);
        $service->addAction(UpdateMediumtextAttribute::getName(), UpdateMediumtextAttribute::class);

        // Attribute: Longtext
        $service->addAction(CreateLongtextAttribute::getName(), CreateLongtextAttribute::class);
        $service->addAction(UpdateLongtextAttribute::getName(), UpdateLongtextAttribute::class);
    }

    private function registerIndexActions(Service $service): void
    {
        $service->addAction(CreateIndex::getName(), CreateIndex::class);
        $service->addAction(GetIndex::getName(), GetIndex::class);
        $service->addAction(DeleteIndex::getName(), DeleteIndex::class);
        $service->addAction(ListIndexes::getName(), ListIndexes::class);
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
