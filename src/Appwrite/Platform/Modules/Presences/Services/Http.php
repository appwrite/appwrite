<?php

namespace Appwrite\Platform\Modules\Presences\Services;

use Appwrite\Platform\Modules\Presences\HTTP\Delete as DeletePresence;
use Appwrite\Platform\Modules\Presences\HTTP\Get as GetPresence;
use Appwrite\Platform\Modules\Presences\HTTP\Update as UpdatePresence;
use Appwrite\Platform\Modules\Presences\HTTP\Upsert as UpsertPresence;
use Appwrite\Platform\Modules\Presences\HTTP\XList as ListPresences;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this
            ->addAction(UpsertPresence::getName(), new UpsertPresence())
            ->addAction(GetPresence::getName(), new GetPresence())
            ->addAction(ListPresences::getName(), new ListPresences())
            ->addAction(UpdatePresence::getName(), new UpdatePresence())
            ->addAction(DeletePresence::getName(), new DeletePresence());
    }
}
