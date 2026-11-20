<?php

namespace Appwrite\Platform\Modules\Alerts\Services;

use Appwrite\Platform\Modules\Alerts\Http\Alerts\Get as GetAlert;
use Appwrite\Platform\Modules\Alerts\Http\Alerts\Update as UpdateAlert;
use Appwrite\Platform\Modules\Alerts\Http\Alerts\XList as ListAlerts;
use Appwrite\Platform\Modules\Alerts\Http\EscalationPolicies\Create as CreateEscalationPolicy;
use Appwrite\Platform\Modules\Alerts\Http\EscalationPolicies\Delete as DeleteEscalationPolicy;
use Appwrite\Platform\Modules\Alerts\Http\EscalationPolicies\Get as GetEscalationPolicy;
use Appwrite\Platform\Modules\Alerts\Http\EscalationPolicies\Init as InitEscalationPolicies;
use Appwrite\Platform\Modules\Alerts\Http\EscalationPolicies\Update as UpdateEscalationPolicy;
use Appwrite\Platform\Modules\Alerts\Http\EscalationPolicies\XList as ListEscalationPolicies;
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
        $this->addAction('updateAlert', UpdateAlert::class);

        $this->addAction('initEscalationPolicies', InitEscalationPolicies::class);
        $this->addAction('createEscalationPolicy', CreateEscalationPolicy::class);
        $this->addAction('listEscalationPolicies', ListEscalationPolicies::class);
        $this->addAction('getEscalationPolicy', GetEscalationPolicy::class);
        $this->addAction('updateEscalationPolicy', UpdateEscalationPolicy::class);
        $this->addAction('deleteEscalationPolicy', DeleteEscalationPolicy::class);
    }
}
