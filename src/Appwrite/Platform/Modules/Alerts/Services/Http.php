<?php

namespace Appwrite\Platform\Modules\Alerts\Services;

use Appwrite\Platform\Modules\Alerts\Http\Init;
use Appwrite\Platform\Modules\Alerts\Http\Rules\Create as CreateRule;
use Appwrite\Platform\Modules\Alerts\Http\Rules\Delete as DeleteRule;
use Appwrite\Platform\Modules\Alerts\Http\Rules\Get as GetRule;
use Appwrite\Platform\Modules\Alerts\Http\Rules\Update as UpdateRule;
use Appwrite\Platform\Modules\Alerts\Http\Rules\XList as ListRules;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(Init::getName(), new Init());

        $this->addAction(CreateRule::getName(), new CreateRule());
        $this->addAction(ListRules::getName(), new ListRules());
        $this->addAction(GetRule::getName(), new GetRule());
        $this->addAction(UpdateRule::getName(), new UpdateRule());
        $this->addAction(DeleteRule::getName(), new DeleteRule());
    }
}
