<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Create as CreateCollection;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Delete as DeleteCollection;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Bulk\Delete as DeleteDocuments;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Bulk\Update as UpdateDocuments;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Bulk\Upsert as UpsertDocuments;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Create as CreateDocument;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Delete as DeleteDocument;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Get as GetDocument;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Queries\Create as CreateDocumentsQuery;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Update as UpdateDocument;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Upsert as UpsertDocument;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\XList as ListDocuments;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Get as GetCollection;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Indexes\Create as CreateIndex;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Indexes\Delete as DeleteIndex;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Indexes\Get as GetIndex;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Indexes\XList as ListIndexes;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Update as UpdateCollection;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\XList as ListCollections;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Create as CreateVectorDatabase;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Delete as DeleteVectorDatabase;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Get as GetVectorDatabase;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\Create as CreateTransaction;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\Delete as DeleteTransaction;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\Get as GetTransaction;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\Operations\Create as CreateOperations;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\Update as UpdateTransaction;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\XList as ListTransactions;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Update as UpdateVectorDatabase;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\XList as ListVectorDatabases;
use Utopia\Platform\Service;

class VectorsDB extends Base
{
    protected function register(Service $service): void
    {
        $this->registerDatabaseActions($service);
        $this->registerCollectionActions($service);
        $this->registerIndexActions($service);
        $this->registerDocumentActions($service);
        $this->registerTransactionActions($service);
    }

    private function registerDatabaseActions(Service $service): void
    {
        $service->addAction('createVectorsDBDatabase', CreateVectorDatabase::class);
        $service->addAction('getVectorsDBDatabase', GetVectorDatabase::class);
        $service->addAction('updateVectorsDBDatabase', UpdateVectorDatabase::class);
        $service->addAction('deleteVectorsDBDatabase', DeleteVectorDatabase::class);
        $service->addAction('listVectorsDBDatabases', ListVectorDatabases::class);
    }

    private function registerCollectionActions(Service $service): void
    {
        $service->addAction('createVectorsDBCollection', CreateCollection::class);
        $service->addAction('getVectorsDBCollection', GetCollection::class);
        $service->addAction('updateVectorsDBCollection', UpdateCollection::class);
        $service->addAction('deleteVectorsDBCollection', DeleteCollection::class);
        $service->addAction('listVectorsDBCollections', ListCollections::class);
    }

    private function registerIndexActions(Service $service): void
    {
        $service->addAction('createVectorsDBIndex', CreateIndex::class);
        $service->addAction('getVectorsDBIndex', GetIndex::class);
        $service->addAction('deleteVectorsDBIndex', DeleteIndex::class);
        $service->addAction('listVectorsDBIndexes', ListIndexes::class);
    }

    private function registerDocumentActions(Service $service): void
    {
        $service->addAction('createVectorsDBDocument', CreateDocument::class);
        $service->addAction('updateVectorsDBDocument', UpdateDocument::class);
        $service->addAction('upsertVectorsDBDocument', UpsertDocument::class);
        $service->addAction('getVectorsDBDocument', GetDocument::class);
        $service->addAction('listVectorsDBDocuments', ListDocuments::class);
        $service->addAction('createVectorsDBDocumentsQuery', CreateDocumentsQuery::class);
        $service->addAction('deleteVectorsDBDocument', DeleteDocument::class);
        $service->addAction('updateVectorsDBDocuments', UpdateDocuments::class);
        $service->addAction('upsertVectorsDBDocuments', UpsertDocuments::class);
        $service->addAction('deleteVectorsDBDocuments', DeleteDocuments::class);
    }

    private function registerTransactionActions(Service $service): void
    {
        $service->addAction('createVectorsDBTransaction', CreateTransaction::class);
        $service->addAction('getVectorsDBTransaction', GetTransaction::class);
        $service->addAction('updateVectorsDBTransaction', UpdateTransaction::class);
        $service->addAction('deleteVectorsDBTransaction', DeleteTransaction::class);
        $service->addAction('listVectorsDBTransactions', ListTransactions::class);
        $service->addAction('createVectorsDBOperations', CreateOperations::class);
    }
}
