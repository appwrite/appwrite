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
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Logs\XList as ListDocumentLogs;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Update as UpdateDocument;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\Upsert as UpsertDocument;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Documents\XList as ListDocuments;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Get as GetCollection;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Indexes\Create as CreateIndex;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Indexes\Delete as DeleteIndex;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Indexes\Get as GetIndex;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Indexes\XList as ListIndexes;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Logs\XList as ListCollectionLogs;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Update as UpdateCollection;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Usage\Get as GetCollectionUsage;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\XList as ListCollections;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Create as CreateVectorDatabase;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Delete as DeleteVectorDatabase;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Embeddings\Text\Create as CreateTextEmbeddings;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Get as GetVectorDatabase;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\Create as CreateTransaction;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\Delete as DeleteTransaction;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\Get as GetTransaction;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\Operations\Create as CreateOperations;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\Update as UpdateTransaction;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Transactions\XList as ListTransactions;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Update as UpdateVectorDatabase;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Usage\Get as GetVectorDatabaseUsage;
use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Usage\XList as ListVectorDatabaseUsage;
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
        $this->registerEmbeddingActions($service);
        $this->registerTransactionActions($service);
    }

    private function registerDatabaseActions(Service $service): void
    {
        $service->addAction(CreateVectorDatabase::getName(), new CreateVectorDatabase());
        $service->addAction(GetVectorDatabase::getName(), new GetVectorDatabase());
        $service->addAction(UpdateVectorDatabase::getName(), new UpdateVectorDatabase());
        $service->addAction(DeleteVectorDatabase::getName(), new DeleteVectorDatabase());
        $service->addAction(ListVectorDatabases::getName(), new ListVectorDatabases());
        $service->addAction(GetVectorDatabaseUsage::getName(), new GetVectorDatabaseUsage());
        $service->addAction(ListVectorDatabaseUsage::getName(), new ListVectorDatabaseUsage());
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

    private function registerIndexActions(Service $service): void
    {
        $service->addAction(CreateIndex::getName(), new CreateIndex());
        $service->addAction(GetIndex::getName(), new GetIndex());
        $service->addAction(DeleteIndex::getName(), new DeleteIndex());
        $service->addAction(ListIndexes::getName(), new ListIndexes());
    }

    private function registerDocumentActions(Service $service): void
    {
        $service->addAction(CreateDocument::getName(), new CreateDocument());
        $service->addAction(UpdateDocument::getName(), new UpdateDocument());
        $service->addAction(UpsertDocument::getName(), new UpsertDocument());
        $service->addAction(GetDocument::getName(), new GetDocument());
        $service->addAction(ListDocuments::getName(), new ListDocuments());
        $service->addAction(DeleteDocument::getName(), new DeleteDocument());
        $service->addAction(UpdateDocuments::getName(), new UpdateDocuments());
        $service->addAction(UpsertDocuments::getName(), new UpsertDocuments());
        $service->addAction(DeleteDocuments::getName(), new DeleteDocuments());
        $service->addAction(ListDocumentLogs::getName(), new ListDocumentLogs());
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

    private function registerEmbeddingActions(Service $service): void
    {
        $service->addAction(CreateTextEmbeddings::getName(), new CreateTextEmbeddings());
    }
}
