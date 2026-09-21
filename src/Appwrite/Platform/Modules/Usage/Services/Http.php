<?php

namespace Appwrite\Platform\Modules\Usage\Services;

use Appwrite\Platform\Modules\Usage\Http\Events\XList as EventsList;
use Appwrite\Platform\Modules\Usage\Http\Gauges\XList as GaugesList;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction(EventsList::getName(), new EventsList());
        $this->addAction(GaugesList::getName(), new GaugesList());
    }
}
