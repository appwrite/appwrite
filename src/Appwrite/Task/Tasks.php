<?php
namespace Appwrite\Task;

use Utopia\Platform\Service;

class Tasks extends Service {
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
