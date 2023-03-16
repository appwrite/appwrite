<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\Validator\Authorization;

class UsageInfCalc extends Action
{
    public static function getName(): string
    {
        return 'usage-inf-calc';
    }

    public function __construct()
    {
        $this
            ->desc('Usage inf calculation')->inject('dbForConsole')
            ->inject('getProjectDB')
            ->callback(fn(Database $dbForConsole, callable $getProjectDB) => $this->action($dbForConsole, $getProjectDB));
    }


    public function action(Database $dbForConsole, callable $getProjectDB): void
    {
        Authorization::disable();
        Authorization::setDefaultStatus(false);

        function createInfMetric($dbForProject, $key, $value): void
        {
            $id = \md5("null_inf_{$key}");
            $dbForProject->createDocument('stats', new Document([
                '$id' => $id,
                'period' => 'inf',
                'time' => null,
                'metric' => '',
                'metricV2' => $key,
                'value' => $value,
                'region' => 'default',
            ]));
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
                break;
            }

            foreach ($projects as $project) {
                Console::log("Checking Project " . $project->getAttribute('name') . " (" . $project->getInternalId() . ")");
                $dbForProject = $getProjectDB($project);
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
                    createInfMetric($dbForProject, '{' . $bucket->getInternalId() . '}.files.storage', $filesStoragePerProject);
                    createInfMetric($dbForProject, '{' . $bucket->getInternalId() . '}.files', $filesCountPerProject);
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
                        $documents = $dbForProject->find('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), []);
                        foreach ($documents as $document) {
                            $documentsCountPerProject++;
                            $documentsCountPerDatabase++;
                            $documentsCountPerCollection++;
                            createInfMetric($dbForProject, '{' . $bucket->getInternalId() . '}.files.storage', $filesStoragePerProject);
                            createInfMetric($dbForProject, '{' . $bucket->getInternalId() . '}.files', $filesCountPerProject);
                        }
                        createInfMetric($dbForProject, '{' . $database->getInternalId() . '}.' . $collection->getId() . '.documents', $documentsCountPerCollection);
                    }
                    createInfMetric($dbForProject, '{' . $database->getInternalId() . '}.documents', $documentsCountPerDatabase);
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
                            $buildDuration += $build['duration'] * 1000;
                            $buildDurationPerProject += $build['duration'] * 1000;
                            $buildCountPerProject++;
                            $buildCount++;
                        }
                    }

                    createInfMetric($dbForProject, $function->getInternalId() . '.builds', $buildCount);
                    createInfMetric($dbForProject, $function->getInternalId() . '.builds.compute', $buildDuration);
                    createInfMetric($dbForProject, $function->getInternalId() . '.deployments', $deploymentCount);
                    createInfMetric($dbForProject, $function->getInternalId() . '.deployments.storage', $deploymentSize);

                    $executions = $dbForProject->find('executions', []);
                    foreach ($executions as $execution) {
                        $executionDuration += $execution['duration'] * 1000;
                        $executionDurationPerProject += $execution['duration'] * 1000;
                        $executionCount++;
                        $executionCountPerProject++;
                    }
                    createInfMetric($dbForProject, $function->getInternalId() . 'executions.compute', $executionDuration);
                    createInfMetric($dbForProject, $function->getInternalId() . '.executions', $executionCount);
                }
                createInfMetric($dbForProject, 'deployments', $deploymentCountPerProject);
                createInfMetric($dbForProject, 'builds', $buildCountPerProject);
                createInfMetric($dbForProject, 'executions', $executionCountPerProject);
                createInfMetric($dbForProject, 'deployments.storage', $deploymentSizePerProject);
                createInfMetric($dbForProject, 'builds.compute', $buildDurationPerProject);
                createInfMetric($dbForProject, 'executions.compute', $executionDurationPerProject);

                $projectCursor = $projects[array_key_last($projects)];
            }
        }
    }
}
