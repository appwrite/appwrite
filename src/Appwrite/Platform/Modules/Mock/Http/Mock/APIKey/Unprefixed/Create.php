<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\APIKey\Unprefixed;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createAPIKeyUnprefixed';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/mock/api-key-unprefixed')
            ->desc('Create API Key (without standard prefix)')
            ->groups(['mock', 'api', 'projects'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->param('projectId', '', new UID(), 'Project ID.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $projectId, Response $response, Database $dbForPlatform): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $scopes = array_keys(Config::getParam('projectScopes'));

        $key = new Document([
            '$id' => Id::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resourceInternalId' => $project->getSequence(),
            'resourceId' => $project->getId(),
            'resourceType' => 'projects',
            'name' => 'Outdated key',
            'scopes' => $scopes,
            'expire' => null,
            'sdks' => [],
            'accessedAt' => null,
            'secret' => \bin2hex(\random_bytes(128)),
        ]);

        $key = $dbForPlatform->createDocument('keys', $key);

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($key, Response::MODEL_KEY);
    }
}
