<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\MockPhone;

use Appwrite\Auth\Validator\Phone;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteProjectMockPhone';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/project/mock-phones/:number')
            ->desc('Delete project mock phone')
            ->groups(['api', 'project'])
            ->label('scope', 'mocks.write')
            ->label('event', 'mock-phones.[number].delete')
            ->label('audits.event', 'project.mock-phone.delete')
            ->label('audits.resource', 'project.mock-phone/{request.number}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'mocks',
                name: 'deleteMockPhone',
                description: <<<EOT
                Delete a mock phone by its unique number. This endpoint removes the mock phone and its OTP configuration from the project.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('number', null, new Phone(), 'Phone number associated with the mock phone. Must be a valid E.164 formatted phone number.')
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('distributedLockOrFail')
            ->callback($this->action(...));
    }

    public function action(
        string $number,
        Response $response,
        QueueEvent $queueForEvents,
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
        callable $distributedLockOrFail,
    ) {
        $distributedLockOrFail("lock:platform:projects:{$project->getId()}", function () use ($project, $number, $dbForPlatform, $authorization) {
            $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $project->getId()));

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

            unset($mockNumbers[$mockNumberIndex]);
            $auths['mockNumbers'] = array_values($mockNumbers);

            $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                'auths' => $auths,
            ])));
        });

        $queueForEvents->setParam('number', $number);

        $response->noContent();
    }
}
