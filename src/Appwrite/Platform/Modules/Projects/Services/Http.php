<?php

namespace Appwrite\Platform\Modules\Projects\Services;

use Appwrite\Platform\Modules\Projects\Http\Init;
use Appwrite\Platform\Modules\Projects\Http\Projects\Auth\MockNumbers\Update as UpdateProjectMockNumbers;
use Appwrite\Platform\Modules\Projects\Http\Projects\Create as CreateProject;
use Appwrite\Platform\Modules\Projects\Http\Projects\OAuth2\Update as UpdateProjectOAuth2;
use Appwrite\Platform\Modules\Projects\Http\Projects\Team\Update as UpdateProjectTeam;
use Appwrite\Platform\Modules\Projects\Http\Projects\Templates\Email\Delete as DeleteProjectEmailTemplate;
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

        $this->addAction('init', Init::class);

        $this->addAction('createProject', CreateProject::class);
        $this->addAction('updateProject', UpdateProject::class);
        $this->addAction('listProjects', ListProjects::class);
        $this->addAction('updateProjectTeam', UpdateProjectTeam::class);
        $this->addAction('updateProjectOAuth2', UpdateProjectOAuth2::class);
        $this->addAction('updateProjectMockNumbers', UpdateProjectMockNumbers::class);
        $this->addAction('deleteProjectEmailTemplate', DeleteProjectEmailTemplate::class);

        $this->addAction('createSchedule', CreateSchedule::class);
        $this->addAction('getSchedule', GetSchedule::class);
        $this->addAction('listSchedules', ListSchedules::class);

        $this->addAction('listStages', ListStages::class);
        $this->addAction('updateStage', UpdateStages::class);
    }
}
