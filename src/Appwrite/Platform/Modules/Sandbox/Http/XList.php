<?php

namespace Appwrite\Platform\Modules\Sandbox\Http;

use Appwrite\Sandbox\Client;
use Appwrite\Sandbox\Exception as SandboxException;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class XList extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'listSandboxes';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/sandbox')
            ->desc('List sandboxes')
            ->groups(['api', 'sandbox'])
            ->label('scope', 'sandboxes.read')
            ->label('sdk', new Method(
                namespace: 'sandbox',
                group: 'sandbox',
                name: 'list',
                description: <<<EOT
                List all live sandboxes in the current project.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SANDBOX_LIST,
                    ),
                ],
            ))
            ->inject('response')
            ->inject('project')
            ->inject('sandboxes')
            ->callback($this->action(...));
    }

    public function action(Response $response, Document $project, Client $sandboxes): void
    {
        $prefix = $this->prefix($project);

        try {
            $statuses = $sandboxes->list();
        } catch (SandboxException $e) {
            throw $this->mapError($e);
        }

        $documents = [];
        foreach ($statuses as $status) {
            if (\str_starts_with((string)($status['id'] ?? ''), $prefix)) {
                $documents[] = $this->document($status, $prefix);
            }
        }

        $response->dynamic(new Document([
            'sandboxes' => $documents,
            'total' => \count($documents),
        ]), Response::MODEL_SANDBOX_LIST);
    }
}
