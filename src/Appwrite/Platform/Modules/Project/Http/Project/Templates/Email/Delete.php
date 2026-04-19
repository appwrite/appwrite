<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Templates\Email;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

class Delete extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'deleteProjectEmailTemplate';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/project/templates/email')
            ->httpAlias('/v1/projects/:projectId/templates/email')
            ->httpAlias('/v1/projects/:projectId/templates/email/:templateId/:locale')
            ->desc('Delete project email template')
            ->groups(['api', 'project'])
            ->label('scope', 'templates.write')
            ->label('event', 'templates.[templateType].delete')
            ->label('audits.event', 'project.template.delete')
            ->label('audits.resource', 'project.template/{response.templateId}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'templates',
                name: 'deleteEmailTemplate',
                description: <<<EOT
                Reset a custom email template to its default value. This endpoint removes any custom content and restores the template to its original state.
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
            ->param('templateId', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? [], true), 'Custom email template type. Can be one of: '.\implode(', ', Config::getParam('locale-templates')['email'] ?? []))
            ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Custom email template locale. If left empty, the fallback locale (en) will be used.', optional: true, injections: ['localeCodes'])
            ->inject('response')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('project')
            ->inject('locale')
            ->callback($this->action(...));
    }

    public function action(
        string $templateId,
        string $locale,
        Response $response,
        QueueEvent $queueForEvents,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
        Locale $localeObject,
    ) {
        $locale = $locale ?: System::getEnv('_APP_LOCALE', 'en');

        $templates = $project->getAttribute('templates', []);
        $template  = $templates['email.' . $templateId . '-' . $locale] ?? null;

        if (is_null($template)) {
            throw new Exception(Exception::PROJECT_TEMPLATE_DEFAULT_DELETION);
        }

        unset($templates['email.' . $templateId . '-' . $locale]);

        $updates = new Document([
            'templates' => $templates,
        ]);

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));

        $queueForEvents->setParam('templateType', $templateId);

        $response->noContent();
    }
}
