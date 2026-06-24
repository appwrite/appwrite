<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator;
use Utopia\Validator\Nullable;
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
            ->label('usage.resource', 'project/{request.projectId}')
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('name', null, new Text(128), 'Project name. Max length: 128 chars.')
            ->param('description', null, new Nullable(new Text(256)), 'Project description. Max length: 256 chars.', true)
            ->param('logo', null, new Nullable(new Text(1024)), 'Project logo.', true)
            ->param('url', null, new Nullable(new URL()), 'Project URL.', true)
            ->param('legalName', null, new Nullable(new Text(256)), 'Project legal name. Max length: 256 chars.', true)
            ->param('legalCountry', null, new Nullable(new Text(256)), 'Project legal country. Max length: 256 chars.', true)
            ->param('legalState', null, new Nullable(new Text(256)), 'Project legal state. Max length: 256 chars.', true)
            ->param('legalCity', null, new Nullable(new Text(256)), 'Project legal city. Max length: 256 chars.', true)
            ->param('legalAddress', null, new Nullable(new Text(256)), 'Project legal address. Max length: 256 chars.', true)
            ->param('legalTaxId', null, new Nullable(new Text(256)), 'Project legal tax ID. Max length: 256 chars.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $name, ?string $description, ?string $logo, ?string $url, ?string $legalName, ?string $legalCountry, ?string $legalState, ?string $legalCity, ?string $legalAddress, ?string $legalTaxId, Response $response, Database $dbForPlatform)
    {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        // Persist only the attributes the caller actually sent. Optional fields
        // are null when omitted, so writing them unconditionally would clear the
        // project's existing description/logo/url/legal details.
        $updates = new Document([
            'name' => $name,
            'search' => implode(' ', [$projectId, $name]),
        ]);

        if ($description !== null) {
            $updates->setAttribute('description', $description);
        }
        if ($logo !== null) {
            $updates->setAttribute('logo', $logo);
        }
        if ($url !== null) {
            $updates->setAttribute('url', $url);
        }
        if ($legalName !== null) {
            $updates->setAttribute('legalName', $legalName);
        }
        if ($legalCountry !== null) {
            $updates->setAttribute('legalCountry', $legalCountry);
        }
        if ($legalState !== null) {
            $updates->setAttribute('legalState', $legalState);
        }
        if ($legalCity !== null) {
            $updates->setAttribute('legalCity', $legalCity);
        }
        if ($legalAddress !== null) {
            $updates->setAttribute('legalAddress', $legalAddress);
        }
        if ($legalTaxId !== null) {
            $updates->setAttribute('legalTaxId', $legalTaxId);
        }

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $updates);

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
