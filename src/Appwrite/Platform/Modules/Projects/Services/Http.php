<?php

namespace Appwrite\Platform\Modules\Projects\Services;

use Appwrite\Platform\Modules\Projects\Http\Projects\Create as CreateProject;
use Appwrite\Platform\Modules\Projects\Http\Projects\Team\Update as UpdateProjectTeam;
use Appwrite\Platform\Modules\Projects\Http\Projects\Update as UpdateProject;
use Appwrite\Platform\Modules\Projects\Http\Projects\XList as ListProjects;
use Appwrite\Platform\Modules\Projects\Http\Schedules\Create as CreateSchedule;
use Appwrite\Platform\Modules\Projects\Http\Schedules\Get as GetSchedule;
use Appwrite\Platform\Modules\Projects\Http\Schedules\XList as ListSchedules;
use Appwrite\Platform\Modules\Projects\Http\Stages\Update as UpdateStages;
use Appwrite\Platform\Modules\Projects\Http\Stages\XList as ListStages;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction(CreateProject::getName(), CreateProject::class);
        $this->addAction(UpdateProject::getName(), UpdateProject::class);
        $this->addAction(ListProjects::getName(), ListProjects::class);
        $this->addAction(UpdateProjectTeam::getName(), UpdateProjectTeam::class);

        $this->addAction(CreateSchedule::getName(), CreateSchedule::class);
        $this->addAction(GetSchedule::getName(), GetSchedule::class);
        $this->addAction(ListSchedules::getName(), ListSchedules::class);

        $this->addAction(ListStages::getName(), ListStages::class);
        $this->addAction(UpdateStages::getName(), UpdateStages::class);
    }
}
