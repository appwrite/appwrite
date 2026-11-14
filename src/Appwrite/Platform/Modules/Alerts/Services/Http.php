<?php

namespace Appwrite\Platform\Modules\Alerts\Services;

use Appwrite\Platform\Modules\Alerts\Http\Alerts\Get as GetAlert;
use Appwrite\Platform\Modules\Alerts\Http\Alerts\Update as UpdateAlert;
use Appwrite\Platform\Modules\Alerts\Http\Alerts\XList as ListAlerts;
use Appwrite\Platform\Modules\Alerts\Http\Init;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\Delete as DeleteMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\DNS\Create as CreateDnsMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\DNS\Update as UpdateDnsMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\Get as GetMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\HTTP\Create as CreateHttpMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\HTTP\Update as UpdateHttpMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\SMTP\Create as CreateSmtpMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\SMTP\Update as UpdateSmtpMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\TCP\Create as CreateTcpMonitor;
use Appwrite\Platform\Modules\Alerts\Http\Monitors\TCP\Update as UpdateTcpMonitor;
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
        $this->addAction('createHttpMonitor', CreateHttpMonitor::class);
        $this->addAction('updateHttpMonitor', UpdateHttpMonitor::class);
        $this->addAction('createDnsMonitor', CreateDnsMonitor::class);
        $this->addAction('updateDnsMonitor', UpdateDnsMonitor::class);
        $this->addAction('createTcpMonitor', CreateTcpMonitor::class);
        $this->addAction('updateTcpMonitor', UpdateTcpMonitor::class);
        $this->addAction('createSmtpMonitor', CreateSmtpMonitor::class);
        $this->addAction('updateSmtpMonitor', UpdateSmtpMonitor::class);
        $this->addAction('listMonitors', ListMonitors::class);
        $this->addAction('getMonitor', GetMonitor::class);
        $this->addAction('deleteMonitor', DeleteMonitor::class);

        $this->addAction('listAlerts', ListAlerts::class);
        $this->addAction('getAlert', GetAlert::class);
        $this->addAction('updateAlert', UpdateAlert::class);
    }
}
