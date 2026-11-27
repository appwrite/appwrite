<?php

namespace Appwrite\Platform\Modules\Distribution\Services;

use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Create as CreateApp;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Delete as DeleteApp;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Get as GetApp;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets\Create as CreateTarget;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets\Delete as DeleteTarget;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets\Get as GetTarget;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets\Update as UpdateTarget;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Targets\XList as ListTargets;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Update as UpdateApp;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables\Create as CreateVariable;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables\Delete as DeleteVariable;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables\Get as GetVariable;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables\Update as UpdateVariable;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables\XList as ListVariables;
use Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\XList as ListApps;
use Appwrite\Platform\Modules\Distribution\Http\Init;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction('init', Init::class);

        $this->addAction(CreateApp::getName(), CreateApp::class);
        $this->addAction(ListApps::getName(), ListApps::class);
        $this->addAction(GetApp::getName(), GetApp::class);
        $this->addAction(UpdateApp::getName(), UpdateApp::class);
        $this->addAction(DeleteApp::getName(), DeleteApp::class);

        $this->addAction(CreateTarget::getName(), CreateTarget::class);
        $this->addAction(ListTargets::getName(), ListTargets::class);
        $this->addAction(GetTarget::getName(), GetTarget::class);
        $this->addAction(UpdateTarget::getName(), UpdateTarget::class);
        $this->addAction(DeleteTarget::getName(), DeleteTarget::class);

        $this->addAction(CreateVariable::getName(), CreateVariable::class);
        $this->addAction(ListVariables::getName(), ListVariables::class);
        $this->addAction(GetVariable::getName(), GetVariable::class);
        $this->addAction(UpdateVariable::getName(), UpdateVariable::class);
        $this->addAction(DeleteVariable::getName(), DeleteVariable::class);
    }
}
