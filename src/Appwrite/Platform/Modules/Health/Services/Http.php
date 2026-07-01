<?php

namespace Appwrite\Platform\Modules\Health\Services;

use Appwrite\Platform\Modules\Health\Http\Health\AntiVirus\Get as GetAntivirus;
use Appwrite\Platform\Modules\Health\Http\Health\Cache\Get as GetCache;
use Appwrite\Platform\Modules\Health\Http\Health\Certificate\Get as GetCertificate;
use Appwrite\Platform\Modules\Health\Http\Health\DB\Get as GetDB;
use Appwrite\Platform\Modules\Health\Http\Health\Get as GetHealth;
use Appwrite\Platform\Modules\Health\Http\Health\PubSub\Get as GetPubSub;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Audits\Get as GetQueueAudits;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Builds\Get as GetQueueBuilds;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Certificates\Get as GetQueueCertificates;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Databases\Get as GetQueueDatabases;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Deletes\Get as GetQueueDeletes;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Failed\Get as GetFailedJobs;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Functions\Get as GetQueueFunctions;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Logs\Get as GetQueueLogs;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Mails\Get as GetQueueMails;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Messaging\Get as GetQueueMessaging;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Migrations\Get as GetQueueMigrations;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\StatsResources\Get as GetQueueStatsResources;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\StatsUsage\Get as GetQueueUsage;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Webhooks\Get as GetQueueWebhooks;
use Appwrite\Platform\Modules\Health\Http\Health\Stats\Get as GetStats;
use Appwrite\Platform\Modules\Health\Http\Health\Storage\Get as GetStorage;
use Appwrite\Platform\Modules\Health\Http\Health\Storage\Local\Get as GetStorageLocal;
use Appwrite\Platform\Modules\Health\Http\Health\Time\Get as GetTime;
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

        $this->addAction(GetQueueAudits::getName(), new GetQueueAudits());
        $this->addAction(GetQueueWebhooks::getName(), new GetQueueWebhooks());
        $this->addAction(GetQueueLogs::getName(), new GetQueueLogs());
        $this->addAction(GetQueueCertificates::getName(), new GetQueueCertificates());
        $this->addAction(GetQueueBuilds::getName(), new GetQueueBuilds());
        $this->addAction(GetQueueDatabases::getName(), new GetQueueDatabases());
        $this->addAction(GetQueueDeletes::getName(), new GetQueueDeletes());
        $this->addAction(GetQueueMails::getName(), new GetQueueMails());
        $this->addAction(GetQueueMessaging::getName(), new GetQueueMessaging());
        $this->addAction(GetQueueMigrations::getName(), new GetQueueMigrations());
        $this->addAction(GetQueueFunctions::getName(), new GetQueueFunctions());
        $this->addAction(GetQueueStatsResources::getName(), new GetQueueStatsResources());
        $this->addAction(GetQueueUsage::getName(), new GetQueueUsage());
        $this->addAction(GetFailedJobs::getName(), new GetFailedJobs());

        $this->addAction(GetStats::getName(), new GetStats());
    }
}
