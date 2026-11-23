<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Distribution\Validator\VariableKey;
use Appwrite\Platform\Permission;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;
    use Permission;

    public static function getName(): string
    {
        return 'createVariable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/apps/:appId/variables')
            ->desc('Create variable')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'variables.[variableId].create')
            ->label('audits.event', 'distribution.variable.create')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'variables',
                name: 'createVariable',
                description: <<<EOT
                Create a new app variable. Variables are passed to the app's builds as environment variables.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_VARIABLE,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App unique ID.', false, ['dbForProject'])
            ->param('variableId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'Variable ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('key', null, new VariableKey(Database::LENGTH_KEY), 'Variable key. Letters, digits and underscores only, must not start with a digit, DISTRIBUTION_ or APPWRITE_. Max length: ' . Database::LENGTH_KEY . ' chars.')
            ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.')
            ->param('secret', true, new Boolean(), 'Secret variables can be updated or deleted, but only builds can read them.', true)
            ->param('description', '', new Text(1024, 0), 'Optional description for the variable.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $variableId,
        string $key,
        string $value,
        bool $secret,
        string $description,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Document $project
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $variableId = $variableId === 'unique()' ? Id::unique() : $variableId;

        try {
            $variable = $dbForProject->createDocument('variables', new Document([
                '$id' => $variableId,
                '$permissions' => $this->getPermissions($project->getAttribute('teamId', ''), $project->getId()),
                'resourceInternalId' => $app->getSequence(),
                'resourceId' => $app->getId(),
                'resourceType' => RESOURCE_TYPE_DISTRIBUTION_APPS,
                'key' => $key,
                'value' => $value,
                'secret' => $secret,
                'description' => $description,
                'search' => \implode(' ', [$variableId, $app->getId(), $key, RESOURCE_TYPE_DISTRIBUTION_APPS]),
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('variableId', $variable->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
