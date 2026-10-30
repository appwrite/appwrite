<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Prefs;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getPrefs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/prefs')
            ->desc('Get project preferences')
            ->groups(['api', 'project'])
            ->label('scope', 'project.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: null,
                name: 'getPrefs',
                description: <<<EOT
                Get the project preferences. Preferences are a key-value object stored on the project for anyone working on it.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PREFERENCES,
                    )
                ],
            ))
            ->inject('response')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(Response $response, Document $project): void
    {
        $prefs = $project->getAttribute('prefs', []);

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    }
}
