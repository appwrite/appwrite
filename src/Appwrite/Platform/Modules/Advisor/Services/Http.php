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

        $this->addAction(GetReport::getName(), new GetReport());
        $this->addAction(ListReports::getName(), new ListReports());
        $this->addAction(DeleteReport::getName(), new DeleteReport());

        $this->addAction(GetInsight::getName(), new GetInsight());
        $this->addAction(ListInsights::getName(), new ListInsights());
    }
}
