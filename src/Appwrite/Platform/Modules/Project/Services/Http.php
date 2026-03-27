<?php

namespace Appwrite\Platform\Modules\Project\Services;

use Appwrite\Platform\Modules\Project\Http\Init;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Android\Create as CreateAndroidPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Android\Update as UpdateAndroidPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Apple\Create as CreateApplePlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Apple\Update as UpdateApplePlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Delete as DeletePlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Get as GetPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Linux\Create as CreateLinuxPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Linux\Update as UpdateLinuxPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Web\Create as CreateWebPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Web\Update as UpdateWebPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Windows\Create as CreateWindowsPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Windows\Update as UpdateWindowsPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\XList as ListPlatforms;
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

        // Variables
        $this->addAction(CreateVariable::getName(), new CreateVariable());
        $this->addAction(ListVariables::getName(), new ListVariables());
        $this->addAction(GetVariable::getName(), new GetVariable());
        $this->addAction(DeleteVariable::getName(), new DeleteVariable());
        $this->addAction(UpdateVariable::getName(), new UpdateVariable());

        // Platforms
        $this->addAction(DeletePlatform::getName(), new DeletePlatform());
        $this->addAction(UpdateWebPlatform::getName(), new UpdateWebPlatform());
        $this->addAction(UpdateApplePlatform::getName(), new UpdateApplePlatform());
        $this->addAction(UpdateAndroidPlatform::getName(), new UpdateAndroidPlatform());
        $this->addAction(UpdateWindowsPlatform::getName(), new UpdateWindowsPlatform());
        $this->addAction(UpdateLinuxPlatform::getName(), new UpdateLinuxPlatform());
        $this->addAction(CreateWebPlatform::getName(), new CreateWebPlatform());
        $this->addAction(CreateApplePlatform::getName(), new CreateApplePlatform());
        $this->addAction(CreateAndroidPlatform::getName(), new CreateAndroidPlatform());
        $this->addAction(CreateWindowsPlatform::getName(), new CreateWindowsPlatform());
        $this->addAction(CreateLinuxPlatform::getName(), new CreateLinuxPlatform());
        $this->addAction(GetPlatform::getName(), new GetPlatform());
        $this->addAction(ListPlatforms::getName(), new ListPlatforms());
    }
}
