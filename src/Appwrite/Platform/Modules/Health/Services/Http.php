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

        $this->addAction('get', GetHealth::class);
        $this->addAction('getVersion', GetHealthVersion::class);
        $this->addAction('getDB', GetDB::class);
        $this->addAction('getCache', GetCache::class);
        $this->addAction('getPubSub', GetPubSub::class);
        $this->addAction('getTime', GetTime::class);
        $this->addAction('getCertificate', GetCertificate::class);
        $this->addAction('getStorageLocal', GetStorageLocal::class);
        $this->addAction('getStorage', GetStorage::class);
        $this->addAction('getAntivirus', GetAntivirus::class);
        $this->addAction('getGeo', GetGeo::class);
        $this->addAction('getUsage', GetUsage::class);

        $this->addAction('getFailedJobs', GetFailedJobs::class);

        $this->addAction('getStats', GetStats::class);
    }
}
