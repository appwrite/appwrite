<?php

namespace Appwrite\Platform\Modules\Databases\Services\Registry;

use Appwrite\Platform\Modules\Databases\Http\Embeddings\Text\Create as CreateTextEmbeddings;
use Utopia\Platform\Service;

class Embeddings extends Base
{
    protected function register(Service $service): void
    {
        $this->registerEmbeddingActions($service);
    }

    private function registerEmbeddingActions(Service $service): void
    {
        $service->addAction(CreateTextEmbeddings::getName(), new CreateTextEmbeddings());
    }
}
