<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\MockPhone;

use Appwrite\Auth\Validator\Phone;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getProjectMockPhone';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/mock-phones/:number')
            ->desc('Get project mock phone')
            ->groups(['api', 'project'])
            ->label('scope', 'mocks.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'mocks',
                name: 'getMockPhone',
                description: <<<EOT
                Get a mock phone by its unique number. This endpoint returns the mock phone's OTP.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MOCK_NUMBER
                    )
                ]
            ))
            ->param('number', null, new Phone(), 'Phone number associated with the mock phone. Must be a valid E.164 formatted phone number.')
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(
        string $number,
        Response $response,
        Document $project
    ) {
        $auths = $project->getAttribute('auths', []);

        $mockNumbers = $auths['mockNumbers'] ?? [];

        $mockNumberIndex = null;
        foreach ($mockNumbers as $index => $mock) {
            if ($mock['phone'] === $number) {
                $mockNumberIndex = $index;
                break;
            }
        }

        if (\is_null($mockNumberIndex)) {
            throw new Exception(Exception::MOCK_NUMBER_NOT_FOUND);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic(new Document($mockNumbers[$mockNumberIndex]), Response::MODEL_MOCK_NUMBER);
    }
}
