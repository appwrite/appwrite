<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\MockPhone;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listProjectMockPhones';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/mock-phones')
            ->desc('List project mock phones')
            ->groups(['api', 'project'])
            ->label('scope', 'mocks.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'mocks',
                name: 'listMockPhones',
                description: <<<EOT
                Get a list of all mock phones in the project. This endpoint returns an array of all mock phones and their OTPs.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MOCK_NUMBER_LIST,
                    )
                ]
            ))
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        bool $includeTotal,
        Response $response,
        Document $project,
    ) {
        $auths = $project->getAttribute('auths', []);
        $mockNumbers = $auths['mockNumbers'] ?? [];

        $total = $includeTotal ? \count($mockNumbers) : 0;

        $mockNumbers = \array_map(fn ($mockNumber) => new Document($mockNumber), $mockNumbers);

        $response->dynamic(new Document([
            'mockNumbers' => $mockNumbers,
            'total' => $total,
        ]), Response::MODEL_MOCK_NUMBER_LIST);
    }
}
