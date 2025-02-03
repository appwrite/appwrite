<?php

namespace Appwrite\Platform\Modules\Sites\Http\Variables;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class ListVariables extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listVariables';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sites/:siteId/variables')
            ->desc('List variables')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.read')
            ->label(
                'sdk',
                new Method(
                    namespace: 'sites',
                    name: 'listVariables',
                    description: '/docs/references/sites/list-variables.md',
                    auth: [AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_VARIABLE_LIST,
                        )
                    ],
                )
            )
            ->param('siteId', '', new UID(), 'Site unique ID.', false)
            ->inject('response')
            ->inject('dbForProject')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, Response $response, Database $dbForProject)
    {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'variables' => $site->getAttribute('vars', []),
            'total' => \count($site->getAttribute('vars', [])),
        ]), Response::MODEL_VARIABLE_LIST);
    }
}
