<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects\Auth\MockNumbers;

use Appwrite\Auth\Validator\MockNumber;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Projects\Http\Projects\Action;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;

/**
 * Backwards compatibility
 */
class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateProjectMockNumbers';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/projects/:projectId/auth/mock-numbers')
            ->desc('Update the mock numbers for the project')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.write')
            ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Project unique ID.', false, ['dbForPlatform'])
            ->param('numbers', '', new ArrayList(new MockNumber(), 10), 'An array of mock numbers and their corresponding verification codes (OTPs). Each number should be a valid E.164 formatted phone number. Maximum of 10 numbers are allowed.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $projectId, array $numbers, Response $response, Database $dbForPlatform): void
    {
        $uniqueNumbers = [];
        foreach ($numbers as $number) {
            if (isset($uniqueNumbers[$number['phone']])) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Duplicate phone numbers are not allowed.');
            }
            $uniqueNumbers[$number['phone']] = $number['otp'];
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);

        $auths['mockNumbers'] = $numbers;

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
