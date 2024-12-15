<?php

namespace Appwrite\Platform\Modules\DevKeys\Services;

use Appwrite\Platform\Modules\DevKeys\Http\DevKeys\CreateKey;
use Appwrite\Platform\Modules\DevKeys\Http\DevKeys\DeleteKey;
use Appwrite\Platform\Modules\DevKeys\Http\DevKeys\GetKey;
use Appwrite\Platform\Modules\DevKeys\Http\DevKeys\ListKeys;
use Appwrite\Platform\Modules\DevKeys\Http\DevKeys\UpdateKey;
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
