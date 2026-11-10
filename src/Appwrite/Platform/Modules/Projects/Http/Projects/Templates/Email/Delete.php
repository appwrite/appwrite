<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects\Templates\Email;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Projects\Http\Projects\Action;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

/**
 * Backwards compatibility
 */
class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteProjectEmailTemplate';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/projects/:projectId/templates/email')
            ->httpAlias('/v1/projects/:projectId/templates/email/:type/:locale')
            ->desc('Delete custom email template')
            ->groups(['api', 'projects'])
            ->label('scope', 'projects.write')
            ->param('projectId', '', fn (Database $dbForPlatform) => new UID($dbForPlatform->getMaxUidLength()), 'Project unique ID.', false, ['dbForPlatform'])
            ->param('type', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? [], true), 'Template type')
            ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', true, ['localeCodes'])
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $type, string $locale, Response $response, Database $dbForPlatform): void
    {
        $locale = $locale ?: System::getEnv('_APP_LOCALE', 'en');

        $project = $dbForPlatform->getDocument('projects', $projectId);
        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $templates = $project->getAttribute('templates', []);
        unset($templates['email.' . $type . '-' . $locale]);

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('templates', $templates));

        $response->noContent();
    }
}
