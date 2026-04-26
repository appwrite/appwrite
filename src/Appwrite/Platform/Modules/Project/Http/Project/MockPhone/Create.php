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

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createProjectMockPhone';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/project/mock-phones')
            ->desc('Create project mock phone')
            ->groups(['api', 'project'])
            ->label('scope', 'mocks.write')
            ->label('event', 'mock-phones.[number].create')
            ->label('audits.event', 'project.mock-phone.create')
            ->label('audits.resource', 'project.mock-phone/{response.number}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'mocks',
                name: 'createMockPhone',
                description: <<<EOT
                Create a new mock phone for your project. Use this endpoint to register a mock phone number and its sign-in OTP for your testers.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_MOCK_NUMBER,
                    )
                ],
            ))
            ->param('number', null, new Phone(), 'Phone number to associate with the mock phone. Must be a valid E.164 formatted phone number.')
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

        if (\count($mockNumbers) >= APP_LIMIT_COUNT) {
            throw new Exception(Exception::MOCK_NUMBER_LIMIT_EXCEEDED);
        }

        foreach ($mockNumbers as $mockNumber) {
            if ($mockNumber['phone'] === $number) {
                throw new Exception(Exception::MOCK_NUMBER_ALREADY_EXISTS);
            }
        }

        // Set to now date
        $mockNumber = [
            'phone' => $number,
            'otp' => $otp,
            '$createdAt' => DateTime::now(),
            '$updatedAt' => DateTime::now(),
        ];

        $mockNumbers[] = $mockNumber;
        $auths['mockNumbers'] = $mockNumbers;

        $updates = new Document([
            'auths' => $auths,
        ]);

        $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));

        $queueForEvents->setParam('number', $number);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document($mockNumber), Response::MODEL_MOCK_NUMBER);
    }
}
