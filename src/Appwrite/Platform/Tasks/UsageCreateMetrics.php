<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\Validator\Authorization;

/**
 * 1. docker compose exec -t appwrite usage-update-metrics
 * 2. docker compose exec -t appwrite usage-create-metrics
 * 3. docker compose exec -t appwrite usage-update-metrics commit=true
 **. docker compose exec -t appwrite usage-update-metrics rollback=true (in case somthing went wrong)
 */

class UsageCreateMetrics extends Action
{
    public static function getName(): string
    {
        return 'usage-create-metrics';
    }

    public function __construct()
    {
        $this
            ->desc('Usage inf metric calculation')->inject('dbForConsole')
            ->inject('getProjectDB')
            ->callback(fn(Database $dbForConsole, callable $getProjectDB) => $this->action($dbForConsole, $getProjectDB));
    }

    /**
     * @throws \Exception
     */
    public function action(Database $dbForConsole, callable $getProjectDB): void
    {
        Authorization::disable();
        Authorization::setDefaultStatus(false);

        function createInfMetric($dbForProject, $key, $value): void
        {
            $id = \md5("null_inf_{$key}");
            $stats = $dbForProject->getDocument('stats', $id);
            if ($stats->isEmpty()) {
                $stats = $dbForProject->createDocument('stats', new Document([
                    '$id' => $id,
                    'period' => 'inf',
                    'time' => null,
                    'metric' => '',
                    'metricV2' => $key,
                    'value' => (int)$value,
                    'region' => 'default',
                ]));
            } else {
                $stats->setAttribute('value', (int)($stats['value'] + $value));
                $dbForProject->updateDocument('stats', $stats->getId(), $stats);
            }

            Console::log($stats->getId() . ', ' . $key . ', inf,' . $stats->getAttribute('value'));
        }

        $limit = 100;
        $projectCursor = null;
        while (true) {
            $projectsQueries = [Query::limit($limit)];
            if ($projectCursor !== null) {
                $projectsQueries[] = Query::cursorAfter($projectCursor);
            }
            $projects = $dbForConsole->find('projects', $projectsQueries);

            if (count($projects) === 0) {
                Console::info("**** Could not found any projects ****");
                break;
            }

            foreach ($projects as $project) {
                Console::info(PHP_EOL . "Project " . $project->getAttribute('name') . " (" . $project->getInternalId() . ")");
                /**
                 * Delete (reset) inf metric if exists (in case of multiple executions)
                 */
                $dbForProject = $getProjectDB($project);
                $statsQueries[] = Query::equal('period', ['inf']);
                $statsCursor = null;
                while (true) {
                    $statsQueries = [Query::limit($limit)];
                    $statsQueries[] = Query::equal('period', ['inf']);
                    if ($statsCursor !== null) {
                        $statsQueries[] = Query::cursorAfter($statsCursor);
                    }

                    $stats = $dbForProject->find('stats', $statsQueries);
                    if (count($stats) === 0) {
                        Console::info("**** Could not found any stats to delete ****");
                        break;
                    }

                    foreach ($stats as $stat) {
                        $dbForProject->deleteDocument('stats', $stat->getId());
                        Console::error('deleting ' . $stat->getId() . ',' . $stat['metricV2']  . ',' . $stat['period']);
                    }

                    $statsCursor = $stats[array_key_last($stats)];
                }

                /**
                 * Network - inbound, outbound, requests
                 */
                $metrics = [];
                $metrics[] = ['from' => 'project.$all.network.outbound', 'to' => 'network.outbound'];
                $metrics[] = ['from' => 'project.$all.network.inbound', 'to' => 'network.inbound'];
                $metrics[] = ['from' => 'project.$all.network.requests', 'to' => 'network.requests'];

                foreach ($metrics as $metric) {
                    $stats = $dbForProject->find('stats', [
                        Query::equal('metric', [$metric['from']]),
                        Query::equal('period', ['1d']),
                        Query::greaterThan('value', 0)
                        ]);
                    foreach ($stats as $stat) {
                        createInfMetric($dbForProject, $metric['to'], $stat['value']);
                    }
                }

                /**
                 * users
                 */
                $users = $dbForProject->count('users', [
                    Query::equal('status', [1]),
                ]);
                createInfMetric($dbForProject, 'users', $users);

                /**
                 * Sessions
                 */
                $sessions = $dbForProject->count('sessions', []);
                createInfMetric($dbForProject, 'sessions', $sessions);

                /**
                 * Buckets, Files
                 */
                $filesStoragePerProject = 0;
                $filesCountPerProject = 0;
                $bucketsCountPerProject = 0;
                $buckets = $dbForProject->find('buckets', []);
                foreach ($buckets as $bucket) {
                    $filesCount = 0;
                    $filesStorage = 0;
                    $files = $dbForProject->find('bucket_' . $bucket->getInternalId(), []);
                    foreach ($files as $file) {
                        $filesStorage += $file['sizeOriginal'];
                        $filesStoragePerProject += $file['sizeOriginal'];
                        $filesCount++;
                        $filesCountPerProject++;
                    }

                    createInfMetric($dbForProject, $bucket->getInternalId() . '.files.storage', $filesStoragePerProject);
                    createInfMetric($dbForProject, $bucket->getInternalId() . '.files', $filesCountPerProject);
                    $bucketsCountPerProject++;
                }
                createInfMetric($dbForProject, 'files.storage', $filesStoragePerProject);
                createInfMetric($dbForProject, 'files', $filesCountPerProject);
                createInfMetric($dbForProject, 'buckets', $bucketsCountPerProject);

                /**
                 * Databases, collections, documents
                 */
                $databasesCountPerProject = 0;
                $collectionsCountPerProject = 0;
                $documentsCountPerProject = 0;
                $databases = $dbForProject->find('databases', []);
                foreach ($databases as $database) {
                    $databasesCountPerProject++;
                    $documentsCountPerDatabase = 0;
                    $collectionCountPerDatabase = 0;
                    $collections = $dbForProject->find('database_' . $database->getInternalId(), []);
                    foreach ($collections as $collection) {
                        $documentsCountPerCollection = 0;
                        $collectionsCountPerProject++;
                        $collectionCountPerDatabase++;
                        $documents = $dbForProject->count('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), []);
                        $documentsCountPerProject += $documents;
                        $documentsCountPerDatabase += $documents;
                        $documentsCountPerCollection += $documents;
                        createInfMetric($dbForProject, $database->getInternalId() . '.' . $collection->getInternalId() . '.documents', $documentsCountPerCollection);
                    }
                    createInfMetric($dbForProject, $database->getInternalId() . '.documents', $documentsCountPerDatabase);
                }
                createInfMetric($dbForProject, 'databases', $databasesCountPerProject);
                createInfMetric($dbForProject, 'collections', $collectionsCountPerProject);
                createInfMetric($dbForProject, 'documents', $documentsCountPerProject);

                /**
                 * Functions, deployments, builds, executions
                 */
                $deploymentSizePerProject = 0;
                $deploymentCountPerProject = 0;
                $buildDurationPerProject = 0;
                $buildCountPerProject = 0;
                $executionCountPerProject = 0;
                $executionDurationPerProject = 0 ;

                $functions = $dbForProject->find('functions', []);
                foreach ($functions as $function) {
                    $deploymentSize = 0;
                    $deploymentCount = 0;
                    $buildDuration = 0;
                    $executionDuration = 0;
                    $executionCount = 0;
                    $buildCount = 0;

                    $deployments = $dbForProject->find('deployments', [
                    Query::equal('resourceId', [$function->getId()]),
                    ]);
                    foreach ($deployments as $deployment) {
                        $deploymentSize += $deployment['size'];
                        $deploymentSizePerProject += $deployment['size'];
                        $deploymentCount++;
                        $deploymentCountPerProject++;

                        $build = $dbForProject->findOne('builds', [
                            Query::equal('deploymentId', [$deployment->getId()]),
                        ]);

                        if (!empty($build)) {
                                $buildDuration += $build['duration'];
                                $buildDurationPerProject += $build['duration'];
                                $buildCountPerProject++;
                                $buildCount++;
                        }
                    }

                    createInfMetric($dbForProject, $function->getInternalId() . '.builds', $buildCount);
                    createInfMetric($dbForProject, $function->getInternalId() . '.builds.compute', (int)($buildDuration * 1000));
                    createInfMetric($dbForProject, $function->getInternalId() . '.deployments', $deploymentCount);
                    createInfMetric($dbForProject, $function->getInternalId() . '.deployments.storage', $deploymentSize);

                    $executions = $dbForProject->find('executions', [
                    Query::equal('functionId', [$function->getId()])
                    ]);
                    foreach ($executions as $execution) {
                        $executionDuration += $execution['duration'];
                        $executionDurationPerProject += $execution['duration'];
                        $executionCount++;
                        $executionCountPerProject++;
                    }

                    createInfMetric($dbForProject, $function->getInternalId() . '.executions.compute', (int)($executionDuration * 1000));
                    createInfMetric($dbForProject, $function->getInternalId() . '.executions', $executionCount);
                }
                createInfMetric($dbForProject, 'deployments', $deploymentCountPerProject);
                createInfMetric($dbForProject, 'builds', $buildCountPerProject);
                createInfMetric($dbForProject, 'executions', $executionCountPerProject);
                createInfMetric($dbForProject, 'deployments.storage', $deploymentSizePerProject);
                createInfMetric($dbForProject, 'builds.compute', (int)($buildDurationPerProject * 1000));
                createInfMetric($dbForProject, 'executions.compute', (int)($executionDurationPerProject * 1000));
            }
            $projectCursor = $projects[array_key_last($projects)];
        }
    }
}
