<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Queue\Failed;

use Appwrite\Event\Database;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Publisher\Audit;
use Appwrite\Event\Publisher\Build as BuildPublisher;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Event\Publisher\Screenshot;
use Appwrite\Event\Publisher\StatsResources as StatsResourcesPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
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
            ->inject('publisherForAudits')
            ->inject('publisherForMails')
            ->inject('queueForFunctions')
            ->inject('publisherForStatsResources')
            ->inject('publisherForUsage')
            ->inject('queueForWebhooks')
            ->inject('publisherForCertificates')
            ->inject('publisherForBuilds')
            ->inject('publisherForMessaging')
            ->inject('publisherForMigrations')
            ->inject('publisherForScreenshots')
            ->callback($this->action(...));
    }

    public function action(
        string $name,
        int|string $threshold,
        Response $response,
        Database $queueForDatabase,
        Delete $queueForDeletes,
        Audit $publisherForAudits,
        MailPublisher $publisherForMails,
        Func $queueForFunctions,
        StatsResourcesPublisher $publisherForStatsResources,
        UsagePublisher $publisherForUsage,
        Webhook $queueForWebhooks,
        Certificate $publisherForCertificates,
        BuildPublisher $publisherForBuilds,
        MessagingPublisher $publisherForMessaging,
        MigrationPublisher $publisherForMigrations,
        Screenshot $publisherForScreenshots,
    ): void {
        $threshold = (int) $threshold;

        $queue = match ($name) {
            System::getEnv('_APP_DATABASE_QUEUE_NAME', Event::DATABASE_QUEUE_NAME) => $queueForDatabase,
            System::getEnv('_APP_DELETE_QUEUE_NAME', Event::DELETE_QUEUE_NAME) => $queueForDeletes,
            System::getEnv('_APP_AUDITS_QUEUE_NAME', Event::AUDITS_QUEUE_NAME) => $publisherForAudits,
            System::getEnv('_APP_MAILS_QUEUE_NAME', Event::MAILS_QUEUE_NAME) => $publisherForMails,
            System::getEnv('_APP_FUNCTIONS_QUEUE_NAME', Event::FUNCTIONS_QUEUE_NAME) => $queueForFunctions,
            System::getEnv('_APP_STATS_RESOURCES_QUEUE_NAME', Event::STATS_RESOURCES_QUEUE_NAME) => $publisherForStatsResources,
            System::getEnv('_APP_STATS_USAGE_QUEUE_NAME', Event::STATS_USAGE_QUEUE_NAME) => $publisherForUsage,
            System::getEnv('_APP_WEBHOOK_QUEUE_NAME', Event::WEBHOOK_QUEUE_NAME) => $queueForWebhooks,
            System::getEnv('_APP_CERTIFICATES_QUEUE_NAME', Event::CERTIFICATES_QUEUE_NAME) => $publisherForCertificates,
            System::getEnv('_APP_BUILDS_QUEUE_NAME', Event::BUILDS_QUEUE_NAME) => $publisherForBuilds,
            System::getEnv('_APP_SCREENSHOTS_QUEUE_NAME', Event::SCREENSHOTS_QUEUE_NAME) => $publisherForScreenshots,
            System::getEnv('_APP_MESSAGING_QUEUE_NAME', Event::MESSAGING_QUEUE_NAME) => $publisherForMessaging,
            System::getEnv('_APP_MIGRATIONS_QUEUE_NAME', Event::MIGRATIONS_QUEUE_NAME) => $publisherForMigrations,
            default => throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Unknown queue name: ' . $name),
        };
        $failed = $queue->getSize(failed: true);

        $this->assertQueueThreshold($failed, $threshold, true);

        $response->dynamic(new Document(['size' => $failed]), Response::MODEL_HEALTH_QUEUE);
    }
}
