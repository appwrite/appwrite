<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Variables;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Distribution\Validator\VariableKey;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateVariable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/apps/:appId/variables/:variableId')
            ->desc('Update variable')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'variables.[variableId].update')
            ->label('audits.event', 'distribution.variable.update')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'variables',
                name: 'updateVariable',
                description: <<<EOT
                Update an app variable by its unique ID. A secret variable cannot be made non-secret.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VARIABLE,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App unique ID.', false, ['dbForProject'])
            ->param('variableId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Variable unique ID.', false, ['dbForProject'])
            ->param('key', null, new Nullable(new VariableKey(Database::LENGTH_KEY)), 'Variable key. Letters, digits and underscores only, must not start with a digit, DISTRIBUTION_ or APPWRITE_. Max length: ' . Database::LENGTH_KEY . ' chars.', true)
            ->param('value', null, new Nullable(new Text(8192, 0)), 'Variable value. Max length: 8192 chars.', true)
            ->param('secret', null, new Nullable(new Boolean()), 'Secret variables can be updated or deleted, but only builds can read them.', true)
            ->param('description', null, new Nullable(new Text(1024, 0)), 'Optional description for the variable.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $variableId,
        ?string $key,
        ?string $value,
        ?bool $secret,
        ?string $description,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $variable = $dbForProject->getDocument('variables', $variableId);

        if (
            $variable->isEmpty()
            || $variable->getAttribute('resourceInternalId') !== $app->getSequence()
            || $variable->getAttribute('resourceType') !== RESOURCE_TYPE_DISTRIBUTION_APPS
        ) {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        if ($variable->getAttribute('secret') === true && $secret === false) {
            throw new Exception(Exception::VARIABLE_CANNOT_UNSET_SECRET);
        }

        if ($key === null && $value === null && $secret === null && $description === null) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'At least one of key, value, secret or description must be provided.');
        }

        $updates = new Document();

        if ($key !== null) {
            $updates
                ->setAttribute('key', $key)
                ->setAttribute('search', \implode(' ', [$variable->getId(), $app->getId(), $key, RESOURCE_TYPE_DISTRIBUTION_APPS]));
        }

        if ($value !== null) {
            $updates->setAttribute('value', $value);
        }

        if ($secret !== null) {
            $updates->setAttribute('secret', $secret);
        }

        if ($description !== null) {
            $updates->setAttribute('description', $description);
        }

        try {
            $variable = $dbForProject->updateDocument('variables', $variable->getId(), $updates);
        } catch (Duplicate) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('variableId', $variable->getId());

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
