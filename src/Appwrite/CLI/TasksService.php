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

class TasksService extends Service
{
    public function __construct()
    {
        $this->type = self::TYPE_CLI;
        $this
            ->addAction(Version::NAME, new Version())
            ->addAction(Usage::NAME, new Usage())
            ->addAction(Vars::NAME, new Vars())
            ->addAction(SSL::NAME, new SSL())
            ->addAction(Doctor::NAME, new Doctor())
            ->addAction(Install::NAME, new Install())
            ->addAction(Maintenance::NAME, new Maintenance())
            ->addAction(Migrate::NAME, new Migrate())
            ->addAction(SDKs::NAME, new SDKs())
            ->addAction(Specs::NAME, new Specs());
    }
}
