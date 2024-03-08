<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Http\Validator\Text;
use Utopia\Platform\Action;

class PatchRecreateRepositoriesDocuments extends Action
{
    public static function getName(): string
    {
        return 'patch-recreate-repositories-documents';
    }

    public function __construct()
    {
        $this
            ->desc('Recreate missing repositories in consoleDB from projectDBs. They can be missing if you used Appwrite 1.4.10 or 1.4.11, and deleted a function.')
            ->param('after', '', new Text(36), 'After cursor', true)
            ->param('projectId', '', new Text(36), 'Select project to validate', true)
            ->inject('dbForConsole')
            ->inject('getProjectDB')
            ->callback(fn ($after, $projectId, $dbForConsole, $getProjectDB) => $this->action($after, $projectId, $dbForConsole, $getProjectDB));
    }

    public function action($after, $projectId, Database $dbForConsole, callable $getProjectDB): void
    {
        Console::info("Starting the patch");

        $startTime = microtime(true);

        if (!empty($projectId)) {
            try {
                $project = $dbForConsole->getDocument('projects', $projectId);
                $dbForProject = call_user_func($getProjectDB, $project);
                $this->recreateRepositories($dbForConsole, $dbForProject, $project);
            } catch (\Throwable $th) {
                Console::error("Unexpected error occured with Project ID {$projectId}");
                Console::error('[Error] Type: ' . get_class($th));
                Console::error('[Error] Message: ' . $th->getMessage());
                Console::error('[Error] File: ' . $th->getFile());
                Console::error('[Error] Line: ' . $th->getLine());
            }
        } else {
            $queries = [];
            if (!empty($after)) {
                Console::info("Iterating remaining projects after project with ID {$after}");
                $project = $dbForConsole->getDocument('projects', $after);
                $queries = [Query::cursorAfter($project)];
            } else {
                Console::info("Iterating all projects");
            }
            $this->foreachDocument($dbForConsole, 'projects', $queries, function (Document $project) use ($getProjectDB, $dbForConsole) {
                $projectId = $project->getId();

                try {
                    $dbForProject = call_user_func($getProjectDB, $project);
                    $this->recreateRepositories($dbForConsole, $dbForProject, $project);
                } catch (\Throwable $th) {
                    Console::error("Unexpected error occured with Project ID {$projectId}");
                    Console::error('[Error] Type: ' . get_class($th));
                    Console::error('[Error] Message: ' . $th->getMessage());
                    Console::error('[Error] File: ' . $th->getFile());
                    Console::error('[Error] Line: ' . $th->getLine());
                }
            });
        }

        $endTime = microtime(true);
        $timeTaken = $endTime - $startTime;

        $hours = (int)($timeTaken / 3600);
        $timeTaken -= $hours * 3600;
        $minutes = (int)($timeTaken / 60);
        $timeTaken -= $minutes * 60;
        $seconds = (int)$timeTaken;
        $milliseconds = ($timeTaken - $seconds) * 1000;
        Console::info("Recreate patch completed in $hours h, $minutes m, $seconds s, $milliseconds mis ( total $timeTaken milliseconds)");
    }

    protected function foreachDocument(Database $database, string $collection, array $queries = [], callable $callback = null): void
    {
        $limit = 1000;
        $results = [];
        $sum = $limit;
        $latestDocument = null;

        while ($sum === $limit) {
            $newQueries = $queries;

            if ($latestDocument != null) {
                array_unshift($newQueries, Query::cursorAfter($latestDocument));
            }
            $newQueries[] = Query::limit($limit);
            $results = $database->find($collection, $newQueries);

            if (empty($results)) {
                return;
            }

            $sum = count($results);

            foreach ($results as $document) {
                if (is_callable($callback)) {
                    $callback($document);
                }
            }
            $latestDocument = $results[array_key_last($results)];
        }
    }

    public function recreateRepositories(Database $dbForConsole, Database $dbForProject, Document $project): void
    {
        $projectId = $project->getId();
        Console::log("Running patch for project {$projectId}");

        $this->foreachDocument($dbForProject, 'functions', [], function (Document $function) use ($dbForProject, $dbForConsole, $project) {
            $isConnected = !empty($function->getAttribute('providerRepositoryId', ''));

            if ($isConnected) {
                $repository = $dbForConsole->getDocument('repositories', $function->getAttribute('repositoryId', ''));

                if ($repository->isEmpty()) {
                    $projectId = $project->getId();
                    $functionId = $function->getId();
                    Console::success("Recreating repositories document for project ID {$projectId}, function ID {$functionId}");

                    $repository = $dbForConsole->createDocument('repositories', new Document([
                        '$id' => ID::unique(),
                        '$permissions' => [
                            Permission::read(Role::any()),
                            Permission::update(Role::any()),
                            Permission::delete(Role::any()),
                        ],
                        'installationId' => $function->getAttribute('installationId', ''),
                        'installationInternalId' => $function->getAttribute('installationInternalId', ''),
                        'projectId' => $project->getId(),
                        'projectInternalId' => $project->getInternalId(),
                        'providerRepositoryId' => $function->getAttribute('providerRepositoryId', ''),
                        'resourceId' => $function->getId(),
                        'resourceInternalId' => $function->getInternalId(),
                        'resourceType' => 'function',
                        'providerPullRequestIds' => []
                    ]));

                    $function = $dbForProject->updateDocument('functions', $function->getId(), $function
                        ->setAttribute('repositoryId', $repository->getId())
                        ->setAttribute('repositoryInternalId', $repository->getInternalId()));

                    $this->foreachDocument($dbForProject, 'deployments', [
                        Query::equal('resourceInternalId', [$function->getInternalId()]),
                        Query::equal('resourceType', ['functions'])
                    ], function (Document $deployment) use ($dbForProject, $repository) {
                        $dbForProject->updateDocument('deployments', $deployment->getId(), $deployment
                            ->setAttribute('repositoryId', $repository->getId())
                            ->setAttribute('repositoryInternalId', $repository->getInternalId()));
                    });
                }
            }
        });
    }
}
