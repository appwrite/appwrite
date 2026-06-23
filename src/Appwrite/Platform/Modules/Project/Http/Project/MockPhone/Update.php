<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\MockPhone;

use Appwrite\Auth\Validator\Phone;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectMockPhone';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/project/mock-phones/:number')
            ->desc('Update project mock phone')
            ->groups(['api', 'project'])
            ->label('scope', 'mocks.write')
            ->label('event', 'mock-phones.[number].update')
            ->label('audits.event', 'project.mock-phone.update')
            ->label('audits.resource', 'project.mock-phone/{response.number}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'mocks',
                name: 'updateMockPhone',
                description: <<<EOT
                Update a mock phone by its unique number. Use this endpoint to update the mock phone's OTP.
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
            ->param('otp', '', new Text(6, 6, Text::NUMBERS), 'One-time password (OTP) to associate with the mock phone. Must be a 6-digit numeric code.')
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $number,
        string $otp,
        Response $response,
        QueueEvent $queueForEvents,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
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

        $mockNumbers[$mockNumberIndex]['otp'] = $otp;
        $mockNumbers[$mockNumberIndex]['$updatedAt'] = DateTime::now();

        $auths['mockNumbers'] = $mockNumbers;

        $updates = new Document([
            'auths' => $auths,
        ]);

        $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));

        $queueForEvents->setParam('number', $number);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->dynamic(new Document($mockNumbers[$mockNumberIndex]), Response::MODEL_MOCK_NUMBER);
    }
}
