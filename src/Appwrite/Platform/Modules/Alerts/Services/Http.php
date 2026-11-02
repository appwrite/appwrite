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

        $this->addAction('init', Init::class);

        $this->addAction('createUsageMonitor', CreateUsageMonitor::class);
        $this->addAction('updateUsageMonitor', UpdateUsageMonitor::class);
        $this->addAction('listMonitors', ListMonitors::class);
        $this->addAction('getMonitor', GetMonitor::class);
        $this->addAction('deleteMonitor', DeleteMonitor::class);

        $this->addAction('listAlerts', ListAlerts::class);
        $this->addAction('getAlert', GetAlert::class);
    }
}
