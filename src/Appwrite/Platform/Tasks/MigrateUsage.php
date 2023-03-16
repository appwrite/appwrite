<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class MigrateUsage extends Action
{
    public static function getName(): string
    {
        return 'migrate-usage';
    }

    public function __construct()
    {
        $this
            ->desc('Migrate old usage metric pattern to new')
            ->param('structure', false, new Boolean(), 'Manipulate usage cols structure', true)
            ->inject('dbForConsole')
            ->inject('getProjectDB')
            ->callback(fn(bool $structure, Database $dbForConsole, callable $getProjectDB) => $this->action($structure, $dbForConsole, $getProjectDB));
    }


    public function action(bool $structure, Database $dbForConsole, callable $getProjectDB): void
    {
        Authorization::disable();
        Authorization::setDefaultStatus(false);


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

                if ($structure) {
                    try {
                        $dbForProject->renameAttribute('stats', 'metric', 'metricOld');
                        $dbForProject->renameAttribute('stats', 'metricV2', 'metric');
                    } catch (\Throwable $th) {
                        Console::Error("Error while trying yo rename col: {$th->getMessage()}");
                    }

                    Console::log("Renaming stats col metric to metricOld, metricV2 to metric");
                    continue;
                }


                /**
                 * Project level
                 */
                $metrics[] = ['from' => 'project.$all.network.outbound', 'to' => 'network.outbound'];
                $metrics[] = ['from' => 'project.$all.network.inbound',  'to' => 'network.inbound'];
                $metrics[] = ['from' => 'project.$all.network.requests', 'to' => 'network.requests'];
                $metrics[] = ['from' => 'executions.$all.compute.total', 'to' => 'executions'];
                $metrics[] = ['from' => 'builds.$all.compute.time', 'to' => 'builds.compute'];
                $metrics[] = ['from' => 'files.$all.storage.size', 'to' => 'files.storage'];
                $metrics[] = ['from' => 'files.$all.count.total', 'to' => 'files'];
                $metrics[] = ['from' => 'buckets.$all.count.total', 'to' => 'buckets'];
                $metrics[] = ['from' => 'collections.$all.count.total', 'to' => 'collections'];
                $metrics[] = ['from' => 'databases.$all.count.total', 'to' => 'databases'];
                $metrics[] = ['from' => 'users.$all.count.total ', 'to' => 'users'];
                $metrics[] = ['from' => 'documents.$all.count.total', 'to' => 'documents'];
                $metrics[] = ['from' => 'documents.$all.count.total', 'to' => 'documents'];

                foreach ($metrics as $metric) {
                    $stats = $dbForProject->find('stats', [
                        Query::equal('metric', [$metric['from']]),
                        Query::greaterThan('value', 0)
                    ]);
                    foreach ($stats as $stat) {
                        $stat->setAttribute('metricV2', $metric['to']);
                        $dbForProject->updateDocument('stats', $stat->getId(), $stat);
                    }
                }

                /**
                 * Databases, collections, documents
                 */
                $databases = $dbForProject->find('databases', []);
                foreach ($databases as $database) {
                    $metrics = [];
                    $metrics[] = [
                        'from' => str_replace('{databaseId}', $database->getId(), 'documents.{databaseId}.count.total'),
                        'to' => str_replace('{databaseInternalId}', $database->getInternalId(), '{databaseInternalId}.collections')];
                    $metrics[] = [
                        'from' => str_replace('{databaseId}', $database->getId(), 'documents.{databaseId}.count.total'),
                        'to' => str_replace('{databaseInternalId}', $database->getInternalId(), '{databaseInternalId}.documents')];
                    foreach ($metrics as $metric) {
                        $stats = $dbForProject->find('stats', [
                            Query::equal('metric', [$metric['from']]),
                            Query::greaterThan('value', 0)
                        ]);

                        foreach ($stats as $stat) {
                            $stat->setAttribute('metricV2', $metric['to']);
                            $dbForProject->updateDocument('stats', $stat->getId(), $stat);
                        }

                        $collections = $dbForProject->find('database_' . $database->getInternalId(), []);

                        foreach ($collections as $collection) {
                            $__metric = [
                                'from' => str_replace(['{databaseId}','{collectionId}'], [$database->getId(), $collection->getId()], 'documents.{databaseId}/{collectionId}.count.total'),
                                'to' => str_replace(['{databaseInternalId}', '{collectionInternalId}'], [$database->getInternalId(), $collection->getInternalId()], '{databaseInternalId}.{collectionInternalId}.documents')];

                            $__stats = $dbForProject->find('stats', [
                                Query::equal('metric', [$__metric['from']]),
                                Query::greaterThan('value', 0)
                            ]);

                            foreach ($__stats as $__stat) {
                                $__stat->setAttribute('metricV2', $__metric['to']);
                                $dbForProject->updateDocument('stats', $__stat->getId(), $__stat);
                            }
                        }
                    }
                }

                /**
                 * executions
                 */
                $functions = $dbForProject->find('functions', []);
                foreach ($functions as $function) {
                    $metrics = [];
                    $metrics[] = [
                        'from' => str_replace('{functionId}', $function->getId(), 'executions.{functionId}.compute.time'),
                        'to' => str_replace('{functionInternalId}', $function->getInternalId(), '{functionInternalId}.executions.compute')];
                    $metrics[] = [
                        'from' => str_replace('{functionId}', $function->getId(), 'executions.{functionId}.compute.total'),
                        'to' => str_replace('{functionInternalId}', $function->getInternalId(), '{functionInternalId}.executions')];
                    foreach ($metrics as $metric) {
                        $stats = $dbForProject->find('stats', [
                            Query::equal('metric', [$metric['from']]),
                            Query::greaterThan('value', 0)
                        ]);

                        foreach ($stats as $stat) {
                            $stat->setAttribute('metricV2', $metric['to']);
                            $dbForProject->updateDocument('stats', $stat->getId(), $stat);
                        }
                    }
                }
                $projectCursor = $projects[array_key_last($projects)];
            }
        }
    }
}
