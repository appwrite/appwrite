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
        $service->addAction(CreateVectorDatabase::getName(), CreateVectorDatabase::class);
        $service->addAction(GetVectorDatabase::getName(), GetVectorDatabase::class);
        $service->addAction(UpdateVectorDatabase::getName(), UpdateVectorDatabase::class);
        $service->addAction(DeleteVectorDatabase::getName(), DeleteVectorDatabase::class);
        $service->addAction(ListVectorDatabases::getName(), ListVectorDatabases::class);
    }

    private function registerCollectionActions(Service $service): void
    {
        $service->addAction(CreateCollection::getName(), CreateCollection::class);
        $service->addAction(GetCollection::getName(), GetCollection::class);
        $service->addAction(UpdateCollection::getName(), UpdateCollection::class);
        $service->addAction(DeleteCollection::getName(), DeleteCollection::class);
        $service->addAction(ListCollections::getName(), ListCollections::class);
    }

    private function registerIndexActions(Service $service): void
    {
        $service->addAction(CreateIndex::getName(), CreateIndex::class);
        $service->addAction(GetIndex::getName(), GetIndex::class);
        $service->addAction(DeleteIndex::getName(), DeleteIndex::class);
        $service->addAction(ListIndexes::getName(), ListIndexes::class);
    }

    private function registerDocumentActions(Service $service): void
    {
        $service->addAction(CreateDocument::getName(), CreateDocument::class);
        $service->addAction(UpdateDocument::getName(), UpdateDocument::class);
        $service->addAction(UpsertDocument::getName(), UpsertDocument::class);
        $service->addAction(GetDocument::getName(), GetDocument::class);
        $service->addAction(ListDocuments::getName(), ListDocuments::class);
        $service->addAction(CreateDocumentsQuery::getName(), CreateDocumentsQuery::class);
        $service->addAction(DeleteDocument::getName(), DeleteDocument::class);
        $service->addAction(UpdateDocuments::getName(), UpdateDocuments::class);
        $service->addAction(UpsertDocuments::getName(), UpsertDocuments::class);
        $service->addAction(DeleteDocuments::getName(), DeleteDocuments::class);
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
