<?php

namespace Appwrite\Platform\Modules\Teams\Services;

use Appwrite\Platform\Modules\Teams\Http\Logs\XList as ListLogs;
use Appwrite\Platform\Modules\Teams\Http\Preferences\Get as GetPreferences;
use Appwrite\Platform\Modules\Teams\Http\Preferences\Update as UpdatePreferences;
use Appwrite\Platform\Modules\Teams\Http\Teams\Create as CreateTeam;
use Appwrite\Platform\Modules\Teams\Http\Teams\Delete as DeleteTeam;
use Appwrite\Platform\Modules\Teams\Http\Teams\Get as GetTeam;
use Appwrite\Platform\Modules\Teams\Http\Teams\Name\Update as UpdateTeamName;
use Appwrite\Platform\Modules\Teams\Http\Teams\XList as ListTeams;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Teams
        $this->addAction(CreateTeam::getName(), new CreateTeam());
        $this->addAction(GetTeam::getName(), new GetTeam());
        $this->addAction(ListTeams::getName(), new ListTeams());
        $this->addAction(DeleteTeam::getName(), new DeleteTeam());
        $this->addAction(UpdateTeamName::getName(), new UpdateTeamName());

        // Preferences
        $this->addAction(GetPreferences::getName(), new GetPreferences());
        $this->addAction(UpdatePreferences::getName(), new UpdatePreferences());

        // Logs
        $this->addAction(ListLogs::getName(), new ListLogs());
    }
}
