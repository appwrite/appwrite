<?php

namespace Appwrite\Platform\Modules\Sites\Http\Variables;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Exception\Duplicate as DuplicateException;
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
        return 'updateVariable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/sites/:siteId/variables/:variableId')
            ->desc('Update variable')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('audits.event', 'variable.update')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('sdk', new Method(
                namespace: 'sites',
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
            ->param('siteId', '', new UID(), 'Site unique ID.', false)
            ->param('variableId', '', new UID(), 'Variable unique ID.', false)
            ->param('key', null, new Text(255), 'Variable key. Max length: 255 chars.', false)
            ->param('value', null, new Nullable(new Text(8192, 0)), 'Variable value. Max length: 8192 chars.', true)
            ->param('secret', null, new Nullable(new Boolean()), 'Secret variables can be updated or deleted, but only sites can read them during build and runtime.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $variableId,
        string $key,
        ?string $value,
        ?bool $secret,
        Response $response,
        Database $dbForProject
    ) {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $variable = $dbForProject->getDocument('variables', $variableId);
        if ($variable === false || $variable->isEmpty() || $variable->getAttribute('resourceInternalId') !== $site->getSequence() || $variable->getAttribute('resourceType') !== 'site') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        if ($variable->getAttribute('secret') === true && $secret === false) {
            throw new Exception(Exception::VARIABLE_CANNOT_UNSET_SECRET);
        }

        $variable
            ->setAttribute('key', $key)
            ->setAttribute('value', $value ?? $variable->getAttribute('value'))
            ->setAttribute('secret', $secret ?? $variable->getAttribute('secret'))
            ->setAttribute('search', implode(' ', [$variableId, $site->getId(), $key, 'site']));

        try {
            $dbForProject->updateDocument('variables', $variable->getId(), $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }

        $dbForProject->updateDocument('sites', $site->getId(), $site->setAttribute('live', false));

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    }
}
