<?php

namespace Appwrite\Platform\Modules\DevelopmentKeys\Services;

use Appwrite\Platform\Modules\DevelopmentKeys\Http\DevelopmentKeys\CreateKey;
use Appwrite\Platform\Modules\DevelopmentKeys\Http\DevelopmentKeys\DeleteKey;
use Appwrite\Platform\Modules\DevelopmentKeys\Http\DevelopmentKeys\GetKey;
use Appwrite\Platform\Modules\DevelopmentKeys\Http\DevelopmentKeys\ListKeys;
use Appwrite\Platform\Modules\DevelopmentKeys\Http\DevelopmentKeys\UpdateKey;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction(CreateKey::getName(), new CreateKey());
        $this->addAction(UpdateKey::getName(), new UpdateKey());
        $this->addAction(GetKey::getName(), new GetKey());
        $this->addAction(ListKeys::getName(), new ListKeys());
        $this->addAction(DeleteKey::getName(), new DeleteKey());
    }
}
