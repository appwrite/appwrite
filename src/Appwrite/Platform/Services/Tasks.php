<?php

namespace Appwrite\Platform\Services;

use Utopia\Platform\Service;
use Appwrite\Platform\Tasks\Doctor;
use Appwrite\Platform\Tasks\Install;
use Appwrite\Platform\Tasks\Maintenance;
use Appwrite\Platform\Tasks\Migrate;
use Appwrite\Platform\Tasks\Schedule;
use Appwrite\Platform\Tasks\SDKs;
use Appwrite\Platform\Tasks\Specs;
use Appwrite\Platform\Tasks\SSL;
use Appwrite\Platform\Tasks\Hamster;
use Appwrite\Platform\Tasks\Usage;
use Appwrite\Platform\Tasks\Vars;
use Appwrite\Platform\Tasks\Version;
use Appwrite\Platform\Tasks\VolumeSync;
use Appwrite\Platform\Tasks\CalcTierStats;
use Appwrite\Platform\Tasks\Upgrade;
use Appwrite\Platform\Tasks\DeleteOrphanedProjects;
use Appwrite\Platform\Tasks\DevGenerateTranslations;
use Appwrite\Platform\Tasks\GetMigrationStats;
use Appwrite\Platform\Tasks\PatchRecreateRepositoriesDocuments;
use Appwrite\Platform\Tasks\ScheduleMessage;

class Tasks extends Service
{
    public function __construct()
    {
        $this->type = self::TYPE_CLI;
        $this
            ->addAction(Version::getName(), new Version())
            ->addAction(Usage::getName(), new Usage())
            ->addAction(Vars::getName(), new Vars())
            ->addAction(SSL::getName(), new SSL())
            ->addAction(Hamster::getName(), new Hamster())
            ->addAction(Doctor::getName(), new Doctor())
            ->addAction(Install::getName(), new Install())
            ->addAction(Upgrade::getName(), new Upgrade())
            ->addAction(Maintenance::getName(), new Maintenance())
            ->addAction(ScheduleMessage::getName(), new ScheduleMessage())
            ->addAction(Schedule::getName(), new Schedule())
            ->addAction(Migrate::getName(), new Migrate())
            ->addAction(SDKs::getName(), new SDKs())
            ->addAction(VolumeSync::getName(), new VolumeSync())
            ->addAction(Specs::getName(), new Specs())
            ->addAction(CalcTierStats::getName(), new CalcTierStats())
            ->addAction(DeleteOrphanedProjects::getName(), new DeleteOrphanedProjects())
            ->addAction(PatchRecreateRepositoriesDocuments::getName(), new PatchRecreateRepositoriesDocuments())
            ->addAction(GetMigrationStats::getName(), new GetMigrationStats())
            ->addAction(DevGenerateTranslations::getName(), new DevGenerateTranslations())

        ;
    }
}
