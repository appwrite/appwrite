<?php

namespace Appwrite\Platform\Modules\Project\Services;

use Appwrite\Platform\Modules\Project\Http\Init;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Create as CreateKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Delete as DeleteKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Get as GetKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Update as UpdateKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\XList as ListKeys;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\Create as CreateVariable;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\Delete as DeleteVariable;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\Get as GetVariable;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\Update as UpdateVariable;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\XList as ListVariables;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Hooks
        $this->addAction(Init::getName(), new Init());

        // Project
        $this->addAction(CreateVariable::getName(), new CreateVariable());
        $this->addAction(ListVariables::getName(), new ListVariables());
        $this->addAction(GetVariable::getName(), new GetVariable());
        $this->addAction(DeleteVariable::getName(), new DeleteVariable());
        $this->addAction(UpdateVariable::getName(), new UpdateVariable());

        // Keys
        $this->addAction(CreateKey::getName(), new CreateKey());
        $this->addAction(ListKeys::getName(), new ListKeys());
        $this->addAction(GetKey::getName(), new GetKey());
        $this->addAction(DeleteKey::getName(), new DeleteKey());
        $this->addAction(UpdateKey::getName(), new UpdateKey());
    }
}
