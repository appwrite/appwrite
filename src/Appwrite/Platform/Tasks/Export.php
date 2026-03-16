<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Validator\Text;

class Export extends Action
{
    public static function getName(): string
    {
        return 'export';
    }

    public function __construct()
    {
        $this
            ->desc('Export project metadata and data to a JSON file')
            ->param('projectId', '', new Text(32), 'Project ID to export')
            ->param('file', 'export.json', new Text(256), 'Output file path', true)
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $file, Database $dbForPlatform, callable $getProjectDB): void
    {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            Console::error("Project $projectId not found.");
            return;
        }

        Console::log("Exporting project: " . $project->getAttribute('name') . " ($projectId)");

        $dbForProject = $getProjectDB($project);
        $export = [
            'project' => $project->getArrayCopy(),
            'collections' => [],
            'buckets' => [],
            'functions' => [],
        ];

        // Export Collections
        Console::log("Exporting collections...");
        $collections = $dbForProject->find('collections', [Query::limit(100)]);
        foreach ($collections as $collection) {
            $id = $collection->getId();
            $export['collections'][$id] = [
                'metadata' => $collection->getArrayCopy(),
                'attributes' => $dbForProject->find('attributes', [Query::equal('collectionInternalId', [$collection->getInternalId()])]),
                'indexes' => $dbForProject->find('indexes', [Query::equal('collectionInternalId', [$collection->getInternalId()])]),
                'documents' => $dbForProject->find($id, [Query::limit(1000)]), // Limit data export for now
            ];
        }

        // Export Buckets
        Console::log("Exporting buckets...");
        $buckets = $dbForProject->find('buckets', [Query::limit(100)]);
        foreach ($buckets as $bucket) {
            $export['buckets'][$bucket->getId()] = $bucket->getArrayCopy();
        }

        // Export Functions
        Console::log("Exporting functions...");
        $functions = $dbForProject->find('functions', [Query::limit(100)]);
        foreach ($functions as $function) {
            $export['functions'][$function->getId()] = $function->getArrayCopy();
        }

        file_put_contents($file, json_encode($export, JSON_PRETTY_PRINT));
        Console::success("Project exported to $file");
    }
}
