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

        $this->addAction(Init::getName(), Init::class);

        $this->addAction(CreateUsageMonitor::getName(), CreateUsageMonitor::class);
        $this->addAction(UpdateUsageMonitor::getName(), UpdateUsageMonitor::class);
        $this->addAction(ListMonitors::getName(), ListMonitors::class);
        $this->addAction(GetMonitor::getName(), GetMonitor::class);
        $this->addAction(DeleteMonitor::getName(), DeleteMonitor::class);

        $this->addAction(ListAlerts::getName(), ListAlerts::class);
        $this->addAction(GetAlert::getName(), GetAlert::class);
    }
}
