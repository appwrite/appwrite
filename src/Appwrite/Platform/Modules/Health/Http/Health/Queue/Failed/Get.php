<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Queue\Failed;

use Appwrite\Event\Audit;
use Appwrite\Event\Build;
use Appwrite\Event\Certificate;
use Appwrite\Event\Database;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Event\Migration;
use Appwrite\Event\Screenshot;
use Appwrite\Event\StatsResources;
use Appwrite\Event\StatsUsage;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Modules\Health\Http\Health\Queue\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\System\System;
use Utopia\Validator\Integer;
use Utopia\Validator\WhiteList;

class Get extends Base
{
    public static function getName(): string
    {
        return 'getFailedJobs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Base::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/queue/failed/:name')
            ->desc('Get number of failed queue jobs')
            ->groups(['api', 'health'])
            ->label('scope', 'health.read')
            ->label('sdk', new Method(
                namespace: 'health',
                group: 'queue',
                name: 'getFailedJobs',
                description: '/docs/references/health/get-failed-queue-jobs.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_HEALTH_QUEUE,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('name', '', new WhiteList([
                System::getEnv('_APP_DATABASE_QUEUE_NAME', Event::DATABASE_QUEUE_NAME),
                System::getEnv('_APP_DELETE_QUEUE_NAME', Event::DELETE_QUEUE_NAME),
                System::getEnv('_APP_AUDITS_QUEUE_NAME', Event::AUDITS_QUEUE_NAME),
                System::getEnv('_APP_MAILS_QUEUE_NAME', Event::MAILS_QUEUE_NAME),
                System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', Event::FUNCTIONS_QUEUE_NAME),
                System::getEnv('_APP_STATS_RESOURCES_QUEUE_NAME', Event::STATS_RESOURCES_QUEUE_NAME),
                System::getEnv('_APP_STATS_USAGE_QUEUE_NAME', Event::STATS_USAGE_QUEUE_NAME),
                System::getEnv('_APP_WEBHOOK_QUEUE_NAME', Event::WEBHOOK_QUEUE_NAME),
                System::getEnv('_APP_CERTIFICATES_QUEUE_NAME', Event::CERTIFICATES_QUEUE_NAME),
                System::getEnv('_APP_BUILDS_QUEUE_NAME', Event::BUILDS_QUEUE_NAME),
                System::getEnv('_APP_SCREENSHOTS_QUEUE_NAME', Event::SCREENSHOTS_QUEUE_NAME),
                System::getEnv('_APP_MESSAGING_QUEUE_NAME', Event::MESSAGING_QUEUE_NAME),
                System::getEnv('_APP_MIGRATIONS_QUEUE_NAME', Event::MIGRATIONS_QUEUE_NAME),
            ]), 'The name of the queue')
            ->param('threshold', 5000, new Integer(true), 'Queue size threshold. When hit (equal or higher), endpoint returns server error. Default value is 5000.', true)
            ->inject('response')
            ->inject('queueForDatabase')
            ->inject('queueForDeletes')
            ->inject('queueForAudits')
            ->inject('queueForMails')
            ->inject('queueForFunctions')
            ->inject('queueForStatsResources')
            ->inject('queueForStatsUsage')
            ->inject('queueForWebhooks')
            ->inject('queueForCertificates')
            ->inject('queueForBuilds')
            ->inject('queueForMessaging')
            ->inject('queueForMigrations')
            ->inject('queueForScreenshots')
            ->callback($this->action(...));
    }

    public function action(
        string $name,
        int|string $threshold,
        Response $response,
        Database $queueForDatabase,
        Delete $queueForDeletes,
        Audit $queueForAudits,
        Mail $queueForMails,
        Func $queueForFunctions,
        StatsResources $queueForStatsResources,
        StatsUsage $queueForStatsUsage,
        Webhook $queueForWebhooks,
        Certificate $queueForCertificates,
        Build $queueForBuilds,
        Messaging $queueForMessaging,
        Migration $queueForMigrations,
        Screenshot $queueForScreenshots,
    ): void {
        $threshold = (int) $threshold;

        $queue = match ($name) {
            System::getEnv('_APP_DATABASE_QUEUE_NAME', Event::DATABASE_QUEUE_NAME) => $queueForDatabase,
            System::getEnv('_APP_DELETE_QUEUE_NAME', Event::DELETE_QUEUE_NAME) => $queueForDeletes,
            System::getEnv('_APP_AUDITS_QUEUE_NAME', Event::AUDITS_QUEUE_NAME) => $queueForAudits,
            System::getEnv('_APP_MAILS_QUEUE_NAME', Event::MAILS_QUEUE_NAME) => $queueForMails,
            System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', Event::FUNCTIONS_QUEUE_NAME) => $queueForFunctions,
            System::getEnv('_APP_STATS_RESOURCES_QUEUE_NAME', Event::STATS_RESOURCES_QUEUE_NAME) => $queueForStatsResources,
            System::getEnv('_APP_STATS_USAGE_QUEUE_NAME', Event::STATS_USAGE_QUEUE_NAME) => $queueForStatsUsage,
            System::getEnv('_APP_WEBHOOK_QUEUE_NAME', Event::WEBHOOK_QUEUE_NAME) => $queueForWebhooks,
            System::getEnv('_APP_CERTIFICATES_QUEUE_NAME', Event::CERTIFICATES_QUEUE_NAME) => $queueForCertificates,
            System::getEnv('_APP_BUILDS_QUEUE_NAME', Event::BUILDS_QUEUE_NAME) => $queueForBuilds,
            System::getEnv('_APP_SCREENSHOTS_QUEUE_NAME', Event::SCREENSHOTS_QUEUE_NAME) => $queueForScreenshots,
            System::getEnv('_APP_MESSAGING_QUEUE_NAME', Event::MESSAGING_QUEUE_NAME) => $queueForMessaging,
            System::getEnv('_APP_MIGRATIONS_QUEUE_NAME', Event::MIGRATIONS_QUEUE_NAME) => $queueForMigrations,
        };
        $failed = $queue->getSize(failed: true);

        $this->assertQueueThreshold($failed, $threshold, true);

        $response->dynamic(new Document(['size' => $failed]), Response::MODEL_HEALTH_QUEUE);
    }
}
