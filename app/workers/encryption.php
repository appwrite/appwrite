<?php

use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Resque\Worker;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../init.php';

Console::title('Encryption V1 Worker');
Console::success(APP_NAME . ' Encryption worker V1 has started', "\n");

class EncryptionV1 extends Worker
{
    public function getName(): string
    {
        return 'encryption';
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $type = $this->args['type'] ?? '';

        switch ($type) {
            case APP_ENCRYPTION_TYPE_PROJECT_MASTER_KEY:
                $project = new Document($this->args['project'] ?? []);
                $this->rotateMasterKeyForProject($project);
                break;
            default:
                Console::error('No encryption operation type: ' . $type);
        }
    }

    protected function rotateMasterKeyForProject(Document $project): void
    {
        $projectId = $project->getId();
        $dbForConsole = $this->getConsoleDB();
        $oldKey = $project->getAttribute('keyId', '');
        $secret = Authorization::skip(fn() => $dbForConsole->createDocument('secrets', new Document([
            '$id' => $dbForConsole->getId(),
            '$read' => [],
            '$write' => [],
            '$collection' => 'secrets',
            'secret' => OpenSSL::secretString(),
        ])));
        $dbForConsole->updateDocument(
            'projects',
            $projectId,
            $project
                ->setAttribute('keyId', $secret->getId())
                ->setAttribute('keyRotationDate', time() + App::getEnv('_APP_KEY_ROTATION_INTERVAL', 60 * 60 * 24 * 90))
        );
    }
}
