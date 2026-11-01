<?php

namespace Appwrite\Platform\Modules\Advisor\Services;

use Appwrite\Platform\Modules\Advisor\Http\Insights\Get as GetInsight;
use Appwrite\Platform\Modules\Advisor\Http\Insights\XList as ListInsights;
use Appwrite\Platform\Modules\Advisor\Http\Reports\Delete as DeleteReport;
use Appwrite\Platform\Modules\Advisor\Http\Reports\Get as GetReport;
use Appwrite\Platform\Modules\Advisor\Http\Reports\XList as ListReports;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(GetReport::getName(), GetReport::class);
        $this->addAction(ListReports::getName(), ListReports::class);
        $this->addAction(DeleteReport::getName(), DeleteReport::class);

        $this->addAction(GetInsight::getName(), GetInsight::class);
        $this->addAction(ListInsights::getName(), ListInsights::class);
    }
}
