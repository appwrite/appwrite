<?php

namespace Appwrite\Platform\Modules\Projects\Services;

use Appwrite\Platform\Modules\Projects\Http\DevKeys\CreateDevKey;
use Appwrite\Platform\Modules\Projects\Http\DevKeys\DeleteDevKey;
use Appwrite\Platform\Modules\Projects\Http\DevKeys\GetDevKey;
use Appwrite\Platform\Modules\Projects\Http\DevKeys\ListDevKeys;
use Appwrite\Platform\Modules\Projects\Http\DevKeys\UpdateDevKey;
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
    }
}
