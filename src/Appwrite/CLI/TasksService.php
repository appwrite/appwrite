<?php

namespace Appwrite\CLI;

use Utopia\Platform\Service;
use Appwrite\CLI\Tasks\Doctor;
use Appwrite\CLI\Tasks\Install;
use Appwrite\CLI\Tasks\Maintenance;
use Appwrite\CLI\Tasks\Migrate;
use Appwrite\CLI\Tasks\SDKs;
use Appwrite\CLI\Tasks\Specs;
use Appwrite\CLI\Tasks\SSL;
use Appwrite\CLI\Tasks\Usage;
use Appwrite\CLI\Tasks\Vars;
use Appwrite\CLI\Tasks\Version;
use VolumeSync;

class TasksService extends Service
{
    public function __construct()
    {
        $this->type = self::TYPE_CLI;
        $this
            ->addAction(Version::getName(), new Version())
            ->addAction(Usage::getName(), new Usage())
            ->addAction(Vars::getName(), new Vars())
            ->addAction(SSL::getName(), new SSL())
            ->addAction(Doctor::getName(), new Doctor())
            ->addAction(Install::getName(), new Install())
            ->addAction(Maintenance::getName(), new Maintenance())
            ->addAction(Migrate::getName(), new Migrate())
            ->addAction(SDKs::getName(), new SDKs())
            ->addAction(VolumeSync::getName(), new VolumeSync())
            ->addAction(Specs::getName(), new Specs());
    }
}
