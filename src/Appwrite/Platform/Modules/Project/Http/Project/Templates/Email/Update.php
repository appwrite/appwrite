<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Templates\Email;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Emails\Validator\Email;
use Utopia\Locale\Locale;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectEmailTemplate';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/templates/email')
            ->httpAlias('/v1/projects/:projectId/templates/email')
            ->httpAlias('/v1/projects/:projectId/templates/email/:templateId/:locale')
            ->desc('Update project email template')
            ->groups(['api', 'project'])
            ->label('scope', 'templates.write')
            ->label('event', 'templates.[templateType].update')
            ->label('audits.event', 'project.template.update')
            ->label('audits.resource', 'project.template/{response.templateId}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'templates',
                name: 'updateEmailTemplate',
                description: <<<EOT
                Update a custom email template for the specified locale and type. Use this endpoint to modify the content of your email templates.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_EMAIL_TEMPLATE,
                    )
                ]
            ))
            ->param('templateId', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? [], true), 'Custom email template type. Can be one of: '.\implode(', ', Config::getParam('locale-templates')['email'] ?? []))
            ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Custom email template locale. If left empty, the fallback locale (en) will be used.', optional: true, injections: ['localeCodes'])
            ->param('subject', '', new Text(255), 'Subject of the email template. Can be up to 255 characters.')
            ->param('message', '', new Text(10485760), 'Plain or HTML body of the email template message. Can be up to 10MB of content.')
            ->param('senderName', '', new Text(255, 0), 'Name of the email sender.', true)
            ->param('senderEmail', '', new Email(), 'Email of the sender.', true)
            ->param('replyTo', '', new Email(), 'Reply to email.', true)
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
        string $subject,
        string $message,
        string $senderName,
        string $senderEmail,
        string $replyTo,
        Response $response,
        QueueEvent $queueForEvents,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
        Locale $localeObject,
    ) {
        $locale = $locale ?: System::getEnv('_APP_LOCALE', 'en');

        $template = [
            'senderName' => $senderName,
            'senderEmail' => $senderEmail,
            'subject' => $subject,
            'replyTo' => $replyTo,
            'message' => $message
        ];

        $templates = $project->getAttribute('templates', []);
        $templates['email.' . $templateId . '-' . $locale] = $template;

        $updates = new Document([
            'templates' => $templates,
        ]);

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));

        $queueForEvents->setParam('templateType', $templateId);

        $response->dynamic(new Document([
            'templateId' => $templateId,
            'locale' => $locale,
            'senderName' => $template['senderName'],
            'senderEmail' => $template['senderEmail'],
            'subject' => $template['subject'],
            'replyTo' => $template['replyTo'],
            'message' => $template['message'],
            'custom' => true,
        ]), Response::MODEL_EMAIL_TEMPLATE);
    }
}
