<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\PaymentsUsage;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Queue\Publisher;
use Utopia\System\System;

class SchedulePaymentsUsage extends Action
{
    public static function getName()
    {
        return 'schedule-payments-usage';
    }

    public function __construct()
    {
        $this
            ->desc('Schedules payments usage sync for active projects')
            ->inject('dbForPlatform')
            ->inject('queueForPaymentsUsage')
            ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, PaymentsUsage $queueForPaymentsUsage): void
    {
        Console::title('Payments usage scheduler V1');
        Console::success('Payments usage scheduler started');

        $interval = (int) System::getEnv('_APP_PAYMENTS_USAGE_SYNC_INTERVAL', '300'); // 5 minutes

        Console::loop(function () use ($dbForPlatform, $queueForPaymentsUsage) {
            Authorization::disable();
            Authorization::setDefaultStatus(false);

            $last24Hours = (new \DateTime())->sub(\DateInterval::createFromDateString('24 hours'));
            $projects = $dbForPlatform->find('projects', [
                Query::greaterThanEqual('accessedAt', DateTime::format($last24Hours)),
                Query::equal('region', [System::getEnv('_APP_REGION', 'default')])
            ]);

            foreach ($projects as $project) {
                /** @var Document $project */
                $payments = (array) $project->getAttribute('payments', []);
                if ((bool) ($payments['enabled'] ?? true) === false) {
                    continue; // skip disabled projects
                }

                $queueForPaymentsUsage
                    ->setProject($project)
                    ->trigger();

                Console::success('Queued payments usage sync for project: ' . $project->getId());
            }

            Authorization::reset();
            Authorization::setDefaultStatus(true);
        }, $interval);
    }
}
