<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Prefs;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Assoc;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePrefs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/project/prefs')
            ->desc('Update project preferences')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('audits.event', 'project.prefs.update')
            ->label('audits.resource', 'project/{project.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: null,
                name: 'updatePrefs',
                description: <<<EOT
                Update the project preferences. The object you pass is stored as is and replaces any previous value. The maximum allowed prefs size is 64kB and throws an error if exceeded.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PREFERENCES,
                    )
                ],
            ))
            ->param('prefs', '', new Assoc(), 'Prefs key-value JSON object.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(array $prefs, Response $response, Database $dbForPlatform, Document $project, Authorization $authorization): void
    {
        $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document(['prefs' => $prefs])));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    }
}
