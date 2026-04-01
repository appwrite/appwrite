<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Variables;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectVariable';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/project/variables/:variableId')
            ->desc('Update project variable')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'variables.[variableId].update')
            ->label('audits.event', 'project.variable.update')
            ->label('audits.resource', 'project.variable/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'variables',
                name: 'updateVariable',
                description: <<<EOT
                Update variable by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VARIABLE,
                    )
                ]
            ))
            ->param('variableId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Variable ID.', false, ['dbForProject'])
            ->param('key', null, new Nullable(new Text(255, 0)), 'Variable key. Max length: 255 chars.', true)
            ->param('value', null, new Nullable(new Text(8192, 0)), 'Variable value. Max length: 8192 chars.', true)
            ->param('secret', null, new Nullable(new Boolean()), 'Secret variables can be updated or deleted, but only projects can read them during build and runtime.', true)
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $variableId,
        ?string $key,
        ?string $value,
        ?bool $secret,
        Response $response,
        QueueEvent $queueForEvents,
        Database $dbForProject,
    ) {
        $variable = $dbForProject->getDocument('variables', $variableId);

        if ($variable->isEmpty() || $variable->getAttribute('resourceType', '') !== 'project') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $isSecretVariable = $variable->getAttribute('secret', false) === true;
        if ($isSecretVariable && $secret === false) {
            throw new Exception(Exception::VARIABLE_CANNOT_UNSET_SECRET);
        }

        if (\is_null($key) && \is_null($value) && \is_null($secret)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID);
        }

        $updates = new Document();

        if (!\is_null($key)) {
            $updates->setAttribute('key', $key);
            $updates->setAttribute('search', implode(' ', [$variableId, $key, 'project']));
        }

        if (!\is_null($value)) {
            $updates->setAttribute('value', $value);
        }

        if (!\is_null($secret)) {
            $updates->setAttribute('secret', $secret);
        }

        try {
            $variable = $dbForProject->updateDocument('variables', $variable->getId(), $updates);
        } catch (Duplicate $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }

        foreach (['functions', 'sites'] as $collection) {
            $dbForProject->updateDocuments($collection, new Document([
                'live' => false
            ]));
        }

        $queueForEvents->setParam('variableId', $variable->getId());

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
