<?php

namespace Appwrite\Platform\Modules\Projects\Services;

use Appwrite\Platform\Modules\Projects\Http\DevKeys\Create as CreateDevKey;
use Appwrite\Platform\Modules\Projects\Http\DevKeys\Delete as DeleteDevKey;
use Appwrite\Platform\Modules\Projects\Http\DevKeys\Get as GetDevKey;
use Appwrite\Platform\Modules\Projects\Http\DevKeys\Update as UpdateDevKey;
use Appwrite\Platform\Modules\Projects\Http\DevKeys\XList as ListDevKeys;
use Appwrite\Platform\Modules\Projects\Http\Projects\XList as ListProjects;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction(CreateDevKey::getName(), new CreateDevKey());
        $this->addAction(UpdateDevKey::getName(), new UpdateDevKey());
        $this->addAction(GetDevKey::getName(), new GetDevKey());
        $this->addAction(ListDevKeys::getName(), new ListDevKeys());
        $this->addAction(DeleteDevKey::getName(), new DeleteDevKey());

        $this->addAction(ListProjects::getName(), new ListProjects());
    }
}
