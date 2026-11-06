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
        $service->addAction('createDatabase', CreateDatabase::class);
        $service->addAction('getDatabase', GetDatabase::class);
        $service->addAction('updateDatabase', UpdateDatabase::class);
        $service->addAction('deleteDatabase', DeleteDatabase::class);
        $service->addAction('listDatabases', ListDatabases::class);
    }

    private function registerCollectionActions(Service $service): void
    {
        $service->addAction('createCollection', CreateCollection::class);
        $service->addAction('getCollection', GetCollection::class);
        $service->addAction('updateCollection', UpdateCollection::class);
        $service->addAction('deleteCollection', DeleteCollection::class);
        $service->addAction('listCollections', ListCollections::class);
    }

    private function registerDocumentActions(Service $service): void
    {
        $service->addAction('createDocument', CreateDocument::class);
        $service->addAction('getDocument', GetDocument::class);
        $service->addAction('updateDocument', UpdateDocument::class);
        $service->addAction('updateDocuments', UpdateDocuments::class);
        $service->addAction('upsertDocument', UpsertDocument::class);
        $service->addAction('upsertDocuments', UpsertDocuments::class);
        $service->addAction('deleteDocument', DeleteDocument::class);
        $service->addAction('deleteDocuments', DeleteDocuments::class);
        $service->addAction('listDocuments', ListDocuments::class);
        $service->addAction('createDocumentsQuery', CreateDocumentsQuery::class);
        $service->addAction('incrementDocumentAttribute', IncrementDocumentAttribute::class);
        $service->addAction('decrementDocumentAttribute', DecrementDocumentAttribute::class);

    }

    private function registerAttributeActions(Service $service): void
    {
        // Attribute top-level actions
        $service->addAction('getAttribute', GetAttribute::class);
        $service->addAction('deleteAttribute', DeleteAttribute::class);
        $service->addAction('listAttributes', ListAttributes::class);

        // Attribute: Boolean
        $service->addAction('createBooleanAttribute', CreateBooleanAttribute::class);
        $service->addAction('updateBooleanAttribute', UpdateBooleanAttribute::class);

        // Attribute: Datetime
        $service->addAction('createDatetimeAttribute', CreateDatetimeAttribute::class);
        $service->addAction('updateDatetimeAttribute', UpdateDatetimeAttribute::class);

        // Attribute: Email
        $service->addAction('createEmailAttribute', CreateEmailAttribute::class);
        $service->addAction('updateEmailAttribute', UpdateEmailAttribute::class);

        // Attribute: Enum
        $service->addAction('createEnumAttribute', CreateEnumAttribute::class);
        $service->addAction('updateEnumAttribute', UpdateEnumAttribute::class);

        // Attribute: Float
        $service->addAction('createFloatAttribute', CreateFloatAttribute::class);
        $service->addAction('updateFloatAttribute', UpdateFloatAttribute::class);

        // Attribute: Integer
        $service->addAction('createIntegerAttribute', CreateIntegerAttribute::class);
        $service->addAction('updateIntegerAttribute', UpdateIntegerAttribute::class);

        // Attribute: BigInt
        $service->addAction('createBigIntAttribute', CreateBigIntAttribute::class);
        $service->addAction('updateBigIntAttribute', UpdateBigIntAttribute::class);

        // Attribute: IP
        $service->addAction('createIpAttribute', CreateIPAttribute::class);
        $service->addAction('updateIpAttribute', UpdateIPAttribute::class);

        // Attribute: Line
        $service->addAction('createLineAttribute', CreateLineAttribute::class);
        $service->addAction('updateLineAttribute', UpdateLineAttribute::class);

        // Attribute: Point
        $service->addAction('createPointAttribute', CreatePointAttribute::class);
        $service->addAction('updatePointAttribute', UpdatePointAttribute::class);

        // Attribute: Polygon
        $service->addAction('createPolygonAttribute', CreatePolygonAttribute::class);
        $service->addAction('updatePolygonAttribute', UpdatePolygonAttribute::class);

        // Attribute: Relationship
        $service->addAction('createRelationshipAttribute', CreateRelationshipAttribute::class);
        $service->addAction('updateRelationshipAttribute', UpdateRelationshipAttribute::class);

        // Attribute: String
        $service->addAction('createStringAttribute', CreateStringAttribute::class);
        $service->addAction('updateStringAttribute', UpdateStringAttribute::class);

        // Attribute: URL
        $service->addAction('createUrlAttribute', CreateURLAttribute::class);
        $service->addAction('updateUrlAttribute', UpdateURLAttribute::class);

        // Attribute: Varchar
        $service->addAction('createVarcharAttribute', CreateVarcharAttribute::class);
        $service->addAction('updateVarcharAttribute', UpdateVarcharAttribute::class);

        // Attribute: Text
        $service->addAction('createTextAttribute', CreateTextAttribute::class);
        $service->addAction('updateTextAttribute', UpdateTextAttribute::class);

        // Attribute: Mediumtext
        $service->addAction('createMediumtextAttribute', CreateMediumtextAttribute::class);
        $service->addAction('updateMediumtextAttribute', UpdateMediumtextAttribute::class);

        // Attribute: Longtext
        $service->addAction('createLongtextAttribute', CreateLongtextAttribute::class);
        $service->addAction('updateLongtextAttribute', UpdateLongtextAttribute::class);
    }

    private function registerIndexActions(Service $service): void
    {
        $service->addAction('createIndex', CreateIndex::class);
        $service->addAction('getIndex', GetIndex::class);
        $service->addAction('deleteIndex', DeleteIndex::class);
        $service->addAction('listIndexes', ListIndexes::class);
    }

    private function registerTransactionActions(Service $service): void
    {
        $service->addAction('createDatabasesTransaction', CreateTransaction::class);
        $service->addAction('getDatabasesTransaction', GetTransaction::class);
        $service->addAction('updateDatabasesTransaction', UpdateTransaction::class);
        $service->addAction('deleteDatabasesTransaction', DeleteTransaction::class);
        $service->addAction('listDatabasesTransactions', ListTransactions::class);
        $service->addAction('createDatabasesTransactionOperations', CreateOperations::class);
    }
}
