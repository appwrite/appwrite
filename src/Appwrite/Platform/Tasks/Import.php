<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Validator\Text;

class Import extends Action
{
    public static function getName(): string
    {
        return 'import';
    }

    public function __construct()
    {
        $this
            ->desc('Import project metadata and data from a JSON file')
            ->param('file', '', new Text(256), 'Input file path')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->callback($this->action(...));
    }

    public function action(string $file, Database $dbForPlatform, callable $getProjectDB): void
    {
        if (!file_exists($file)) {
            Console::error("File $file not found.");
            return;
        }

        $import = json_decode(file_get_contents($file), true);
        if (!$import) {
            Console::error("Failed to parse $file");
            return;
        }

        $projectData = $import['project'];
        $projectData['$id'] = ID::unique();
        $projectData['name'] .= ' (Imported)';

        Console::log("Importing project: " . $projectData['name']);

        $project = $dbForPlatform->createDocument('projects', new Document($projectData));
        $dbForProject = $getProjectDB($project);

        // Import Collections
        foreach ($import['collections'] as $id => $data) {
            Console::log("Importing collection: $id");
            $dbForProject->createDocument('collections', new Document($data['metadata']));
            
            foreach ($data['attributes'] as $attr) {
                $dbForProject->createDocument('attributes', new Document($attr));
            }
            foreach ($data['indexes'] as $index) {
                $dbForProject->createDocument('indexes', new Document($index));
            }
            foreach ($data['documents'] as $doc) {
                $dbForProject->createDocument($id, new Document($doc));
            }
        }

        // Import Buckets
        foreach ($import['buckets'] as $id => $data) {
            Console::log("Importing bucket: $id");
            $dbForProject->createDocument('buckets', new Document($data));
        }

        // Import Functions
        foreach ($import['functions'] as $id => $data) {
            Console::log("Importing function: $id");
            $dbForProject->createDocument('functions', new Document($data));
        }

        Console::success("Project imported successfully with ID: " . $project->getId());
    }
}
