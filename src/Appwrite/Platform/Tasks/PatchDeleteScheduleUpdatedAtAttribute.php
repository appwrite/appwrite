<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\Query;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Group;

class PatchDeleteScheduleUpdatedAtAttribute extends Action
{
    public static function getName(): string
    {
        return 'patch-delete-schedule-updated-at-attribute';
    }

    public function __construct()
    {
        $this
            ->desc('Ensure function collections do not have scheduleUpdatedAt attribute')
            ->inject('pools')
            ->inject('dbForConsole')
            ->inject('getProjectDB')
            ->callback(fn (Group $pools, Database $dbForConsole, callable $getProjectDB) => $this->action($pools, $dbForConsole, $getProjectDB));
    }

    /**
     * Iterate over every function on every project to make sure there is a schedule. If not, recreate the schedule.
     */
    public function action(Group $pools, Database $dbForConsole, callable $getProjectDB): void
    {
        Authorization::disable();
        Authorization::setDefaultStatus(false);

        Console::title('PatchDeleteScheduleUpdatedAtAttribute V1');
        Console::success(APP_NAME . ' PatchDeleteScheduleUpdatedAtAttribute v1 has started');

        $limit = 100;
        $projectCursor = null;
        while (true) {
            $projectsQueries = [Query::limit($limit)];
            if ($projectCursor !== null) {
                $projectsQueries[] = Query::cursorAfter($projectCursor);
            }
            $projects = $dbForConsole->find('projects', $projectsQueries);

            if (count($projects) === 0) {
                break;
            }

            foreach ($projects as $project) {
                Console::log("Checking Project " . $project->getAttribute('name') . " (" . $project->getId() . ")");
                $dbForProject = $getProjectDB($project);

                try {
                    /**
                     * Delete 'scheduleUpdatedAt' attribute
                     */
                    $dbForProject->deleteAttribute('functions', 'scheduleUpdatedAt');
                    $dbForProject->deleteCachedCollection('functions');
                    Console::success("'scheduleUpdatedAt' deleted.");
                } catch (\Throwable $th) {
                    Console::warning("'scheduleUpdatedAt' errored: {$th->getMessage()}");
                }

                $pools->reclaim();
            }

            $projectCursor = $projects[array_key_last($projects)];
        }
    }
}
