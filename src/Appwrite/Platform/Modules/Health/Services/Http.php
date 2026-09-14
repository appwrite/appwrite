<?php

namespace Appwrite\Platform\Modules\Health\Services;

use Appwrite\Platform\Modules\Health\Http\Health\AntiVirus\Get as GetAntivirus;
use Appwrite\Platform\Modules\Health\Http\Health\Cache\Get as GetCache;
use Appwrite\Platform\Modules\Health\Http\Health\Certificate\Get as GetCertificate;
use Appwrite\Platform\Modules\Health\Http\Health\DB\Get as GetDB;
use Appwrite\Platform\Modules\Health\Http\Health\Geo\Get as GetGeo;
use Appwrite\Platform\Modules\Health\Http\Health\Get as GetHealth;
use Appwrite\Platform\Modules\Health\Http\Health\PubSub\Get as GetPubSub;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Failed\Get as GetFailedJobs;
use Appwrite\Platform\Modules\Health\Http\Health\Stats\Get as GetStats;
use Appwrite\Platform\Modules\Health\Http\Health\Storage\Get as GetStorage;
use Appwrite\Platform\Modules\Health\Http\Health\Storage\Local\Get as GetStorageLocal;
use Appwrite\Platform\Modules\Health\Http\Health\Time\Get as GetTime;
use Appwrite\Platform\Modules\Health\Http\Health\Usage\Get as GetUsage;
use Appwrite\Platform\Modules\Health\Http\Health\Version\Get as GetHealthVersion;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(GetHealth::getName(), new GetHealth());
        $this->addAction(GetHealthVersion::getName(), new GetHealthVersion());
        $this->addAction(GetDB::getName(), new GetDB());
        $this->addAction(GetCache::getName(), new GetCache());
        $this->addAction(GetPubSub::getName(), new GetPubSub());
        $this->addAction(GetTime::getName(), new GetTime());
        $this->addAction(GetCertificate::getName(), new GetCertificate());
        $this->addAction(GetStorageLocal::getName(), new GetStorageLocal());
        $this->addAction(GetStorage::getName(), new GetStorage());
        $this->addAction(GetAntivirus::getName(), new GetAntivirus());
        $this->addAction(GetGeo::getName(), new GetGeo());
        $this->addAction(GetUsage::getName(), new GetUsage());

        $this->addAction(GetFailedJobs::getName(), new GetFailedJobs());

        $this->addAction(GetStats::getName(), new GetStats());
    }
}
