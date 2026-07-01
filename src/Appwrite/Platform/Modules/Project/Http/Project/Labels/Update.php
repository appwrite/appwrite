<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Labels;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectLabels';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/project/labels')
            ->httpAlias('/v1/projects/:projectId/labels')
            ->desc('Update project labels')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            // ->label('event', 'project.labels.update')
            ->label('audits.event', 'project.labels.update')
            ->label('audits.resource', 'project.labels/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: null,
                name: 'updateLabels',
                description: <<<EOT
                Update the project labels. Labels can be used to easily filter projects in an organization.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('labels', [], new ArrayList(new Text(36, allowList: [...Text::NUMBERS, ...Text::ALPHABET_UPPER, ...Text::ALPHABET_LOWER]), APP_LIMIT_ARRAY_LABELS_SIZE), 'Array of project labels. Replaces the previous labels. Maximum of ' . APP_LIMIT_ARRAY_LABELS_SIZE . ' labels are allowed, each up to 36 alphanumeric characters long.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<string> $labels
     */
    public function action(
        array $labels,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization
    ): void {
        $labels = (array) \array_values(\array_unique($labels));

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document(['labels' => $labels])));

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
