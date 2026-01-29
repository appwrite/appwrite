<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator;
use Utopia\Validator\Text;
use Utopia\Validator\URL;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProject';
    }

    protected function getQueriesValidator(): Validator
    {
        return new Projects();
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/projects/:projectId')
            ->desc('Update project')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.write')
            ->label('audits.event', 'projects.update')
            ->label('audits.resource', 'project/{request.projectId}')
            ->label('sdk', new Method(
                namespace: 'projects',
                group: 'projects',
                name: 'update',
                description: '/docs/references/projects/update.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ]
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('name', null, new Text(128), 'Project name. Max length: 128 chars.')
            ->param('description', '', new Text(256), 'Project description. Max length: 256 chars.', true)
            ->param('logo', '', new Text(1024), 'Project logo.', true)
            ->param('url', '', new URL(), 'Project URL.', true)
            ->param('legalName', '', new Text(256), 'Project legal name. Max length: 256 chars.', true)
            ->param('legalCountry', '', new Text(256), 'Project legal country. Max length: 256 chars.', true)
            ->param('legalState', '', new Text(256), 'Project legal state. Max length: 256 chars.', true)
            ->param('legalCity', '', new Text(256), 'Project legal city. Max length: 256 chars.', true)
            ->param('legalAddress', '', new Text(256), 'Project legal address. Max length: 256 chars.', true)
            ->param('legalTaxId', '', new Text(256), 'Project legal tax ID. Max length: 256 chars.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $name, string $description, string $logo, string $url, string $legalName, string $legalCountry, string $legalState, string $legalCity, string $legalAddress, string $legalTaxId, Response $response, Database $dbForPlatform)
    {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('name', $name)
            ->setAttribute('description', $description)
            ->setAttribute('logo', $logo)
            ->setAttribute('url', $url)
            ->setAttribute('legalName', $legalName)
            ->setAttribute('legalCountry', $legalCountry)
            ->setAttribute('legalState', $legalState)
            ->setAttribute('legalCity', $legalCity)
            ->setAttribute('legalAddress', $legalAddress)
            ->setAttribute('legalTaxId', $legalTaxId)
            ->setAttribute('search', implode(' ', [$projectId, $name])));

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}