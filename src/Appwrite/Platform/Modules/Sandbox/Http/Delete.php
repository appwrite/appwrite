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

class Delete extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteSandbox';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/sandbox/:sandboxId')
            ->desc('Delete sandbox')
            ->groups(['api', 'sandbox'])
            ->label('scope', 'sandboxes.write')
            ->label('sdk', new Method(
                namespace: 'sandbox',
                group: 'sandbox',
                name: 'delete',
                description: <<<EOT
                Delete a sandbox by its unique ID. Teardown is immediate and invalidates the sandbox URL.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
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
        try {
            $sandboxes->delete($this->prefix($project) . $sandboxId);
        } catch (ApiException $e) {
            throw $this->mapError($e);
        }

        $response->noContent();
    }
}
