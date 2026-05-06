<?php

namespace Appwrite\Platform\Modules\Analytics\Services;

use Appwrite\Platform\Modules\Analytics\Http\Apps\Create as CreateApp;
use Appwrite\Platform\Modules\Analytics\Http\Apps\Get as GetApp;
use Appwrite\Platform\Modules\Analytics\Http\Events\Create as CreateEvent;
use Appwrite\Platform\Modules\Analytics\Http\Script\Get as GetScript;
use Appwrite\Platform\Modules\Analytics\Http\Stats\Get as GetStats;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Apps
        $this->addAction(CreateApp::getName(), new CreateApp());
        $this->addAction(GetApp::getName(), new GetApp());

        // Event ingestion
        $this->addAction(CreateEvent::getName(), new CreateEvent());

        // Stats
        $this->addAction(GetStats::getName(), new GetStats());

        // Tracking script
        $this->addAction(GetScript::getName(), new GetScript());
    }
}
