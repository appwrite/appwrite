<?php

namespace Appwrite\Platform\Modules\Sandbox\Http;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use OpenRuntimes\Orchestrator\Exception\ApiException;
use OpenRuntimes\Orchestrator\Sandboxes;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'getSandbox';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sandbox/:sandboxId')
            ->desc('Get sandbox')
            ->groups(['api', 'sandbox'])
            ->label('scope', 'sandboxes.read')
            ->label('sdk', new Method(
                namespace: 'sandbox',
                group: 'sandbox',
                name: 'get',
                description: <<<EOT
                Get a sandbox by its unique ID.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SANDBOX,
                    ),
                ],
            ))
            ->param('sandboxId', '', new Text(36), 'Sandbox ID.')
            ->inject('response')
            ->inject('project')
            ->inject('sandboxes')
            ->callback($this->action(...));
    }

    public function action(string $sandboxId, Response $response, Document $project, Sandboxes $sandboxes): void
    {
        $prefix = $this->prefix($project);

        try {
            $status = $sandboxes->get($prefix . $sandboxId);
        } catch (ApiException $e) {
            throw $this->mapError($e);
        }

        $response->dynamic($this->document($status, $prefix), Response::MODEL_SANDBOX);
    }
}
