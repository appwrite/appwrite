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

        $this->addAction(GetHealth::getName(), GetHealth::class);
        $this->addAction(GetHealthVersion::getName(), GetHealthVersion::class);
        $this->addAction(GetDB::getName(), GetDB::class);
        $this->addAction(GetCache::getName(), GetCache::class);
        $this->addAction(GetPubSub::getName(), GetPubSub::class);
        $this->addAction(GetTime::getName(), GetTime::class);
        $this->addAction(GetCertificate::getName(), GetCertificate::class);
        $this->addAction(GetStorageLocal::getName(), GetStorageLocal::class);
        $this->addAction(GetStorage::getName(), GetStorage::class);
        $this->addAction(GetAntivirus::getName(), GetAntivirus::class);
        $this->addAction(GetGeo::getName(), GetGeo::class);
        $this->addAction(GetUsage::getName(), GetUsage::class);

        $this->addAction(GetFailedJobs::getName(), GetFailedJobs::class);

        $this->addAction(GetStats::getName(), GetStats::class);
    }
}
