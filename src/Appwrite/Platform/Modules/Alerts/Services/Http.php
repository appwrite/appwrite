<?php

namespace Appwrite\Platform\Modules\Alerts\Services;

use Appwrite\Platform\Modules\Alerts\Http\Alerts\Get as GetAlert;
use Appwrite\Platform\Modules\Alerts\Http\Alerts\XList as ListAlerts;
use Appwrite\Platform\Modules\Alerts\Http\Init;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\Delete as DeleteMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\Get as GetMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\Usage\Create as CreateUsageMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\Usage\Update as UpdateUsageMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\XList as ListMonitors;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(Init::getName(), new Init());

        $this->addAction(CreateUsageMonitor::getName(), new CreateUsageMonitor());
        $this->addAction(UpdateUsageMonitor::getName(), new UpdateUsageMonitor());
        $this->addAction(ListMonitors::getName(), new ListMonitors());
        $this->addAction(GetMonitor::getName(), new GetMonitor());
        $this->addAction(DeleteMonitor::getName(), new DeleteMonitor());

        $this->addAction(ListAlerts::getName(), new ListAlerts());
        $this->addAction(GetAlert::getName(), new GetAlert());
    }
}
