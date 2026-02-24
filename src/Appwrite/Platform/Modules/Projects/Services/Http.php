<?php

namespace Appwrite\Platform\Modules\Projects\Services;

use Appwrite\Platform\Modules\Projects\Http\DevKeys\Create as CreateDevKey;
use Appwrite\Platform\Modules\Projects\Http\DevKeys\Delete as DeleteDevKey;
use Appwrite\Platform\Modules\Projects\Http\DevKeys\Get as GetDevKey;
use Appwrite\Platform\Modules\Projects\Http\DevKeys\Update as UpdateDevKey;
use Appwrite\Platform\Modules\Projects\Http\DevKeys\XList as ListDevKeys;
use Appwrite\Platform\Modules\Projects\Http\Projects\Create as CreateProject;
use Appwrite\Platform\Modules\Projects\Http\Projects\Labels\Update as UpdateProjectLabels;
use Appwrite\Platform\Modules\Projects\Http\Projects\Team\Update as UpdateProjectTeam;
use Appwrite\Platform\Modules\Projects\Http\Projects\Update as UpdateProject;
use Appwrite\Platform\Modules\Projects\Http\Projects\XList as ListProjects;
use Appwrite\Platform\Modules\Projects\Http\Schedules\Create as CreateSchedule;
use Appwrite\Platform\Modules\Projects\Http\Schedules\Get as GetSchedule;
use Appwrite\Platform\Modules\Projects\Http\Schedules\XList as ListSchedules;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this->addAction(CreateDevKey::getName(), new CreateDevKey());
        $this->addAction(UpdateDevKey::getName(), new UpdateDevKey());
        $this->addAction(GetDevKey::getName(), new GetDevKey());
        $this->addAction(ListDevKeys::getName(), new ListDevKeys());
        $this->addAction(DeleteDevKey::getName(), new DeleteDevKey());

        $this->addAction(CreateProject::getName(), new CreateProject());
        $this->addAction(UpdateProject::getName(), new UpdateProject());
        $this->addAction(ListProjects::getName(), new ListProjects());
        $this->addAction(UpdateProjectLabels::getName(), new UpdateProjectLabels());
        $this->addAction(UpdateProjectTeam::getName(), new UpdateProjectTeam());

        $this->addAction(CreateSchedule::getName(), new CreateSchedule());
        $this->addAction(GetSchedule::getName(), new GetSchedule());
        $this->addAction(ListSchedules::getName(), new ListSchedules());
    }
}
