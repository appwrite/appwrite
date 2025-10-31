<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections\Create as CreateCollection;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections\Delete as DeleteCollection;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections\Get as GetCollection;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections\Logs\XList as ListCollectionLogs;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections\Update as UpdateCollection;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections\Usage\Get as GetCollectionUsage;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections\XList as ListCollections;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Create as CreateVectorDatabase;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Delete as DeleteVectorDatabase;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Get as GetVectorDatabase;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Update as UpdateVectorDatabase;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Usage\Get as GetVectorDatabaseUsage;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Usage\XList as ListVectorDatabaseUsage;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\XList as ListVectorDatabases;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections\Documents\Embedding\Create as CreateEmbeddingDocument;
use Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections\Documents\Embedding\Update as UpdateEmbeddingDocument;
use Utopia\Platform\Service;

class VectorDB extends Base
{
    protected function register(Service $service): void
    {
        $this->registerDatabaseActions($service);
        $this->registerCollectionActions($service);
        // $this->registerIndexActions($service);
        $this->registerDocumentActions($service);
        // $this->registerTransactionActions($service);
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

    private function registerDocumentActions(Service $service):void{
        $service->addAction(CreateEmbeddingDocument::getName(), new CreateEmbeddingDocument());
        $service->addAction(UpdateEmbeddingDocument::getName(), new UpdateEmbeddingDocument());
    }
}
