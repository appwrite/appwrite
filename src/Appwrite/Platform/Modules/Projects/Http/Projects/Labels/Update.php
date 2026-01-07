<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects\Labels;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectLabels';
    }

    protected function getQueriesValidator(): Validator
    {
        return new Projects();
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/projects/:projectId/labels')
            ->desc('Update project labels')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.write')
            ->label('sdk', new Method(
                namespace: 'projects',
                group: 'projects',
                name: 'updateLabels',
                description: <<<EOT
                Update the project labels by its unique ID. Labels can be used to easily filter projects in an organization.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('labels', [], new ArrayList(new Text(36, allowList: [...Text::NUMBERS, ...Text::ALPHABET_UPPER, ...Text::ALPHABET_LOWER]), APP_LIMIT_ARRAY_LABELS_SIZE), 'Array of project labels. Replaces the previous labels. Maximum of ' . APP_LIMIT_ARRAY_LABELS_SIZE . ' labels are allowed, each up to 36 alphanumeric characters long.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $labels
     */
    public function action(
        string $projectId,
        array $labels,
        Response $response,
        Database $dbForPlatform
    ): void {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $project->setAttribute('labels', (array) \array_values(\array_unique($labels)));

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project);

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
