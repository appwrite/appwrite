<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\PhoneOtpChannel;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectPhoneOtpChannelPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/phone-otp-channel')
            ->desc('Update phone OTP channel policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updatePhoneOtpChannelPolicy',
                description: <<<EOT
                Updating this policy allows you to control how phone OTP messages are delivered to your users. Choose `sms` to always send over SMS, `whatsapp` to always send over WhatsApp, or `whatsapp-sms` to send over WhatsApp and fall back to SMS. Any channel other than `sms` requires a configured WhatsApp provider. The `whatsapp-sms` fallback covers API-level rejections only: Meta accepts a message to a number with no WhatsApp account and reports the failure asynchronously, so that case does not currently fall back to SMS.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('channel', '', new WhiteList([PHONE_OTP_CHANNEL_SMS, PHONE_OTP_CHANNEL_WHATSAPP, PHONE_OTP_CHANNEL_WHATSAPP_SMS], true), 'Channel used to deliver phone OTP messages. Can be one of: ' . PHONE_OTP_CHANNEL_SMS . ', ' . PHONE_OTP_CHANNEL_WHATSAPP . ', ' . PHONE_OTP_CHANNEL_WHATSAPP_SMS . '.', enum: new Enum(name: 'ProjectPhoneOtpChannel'))
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $channel,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        if ($channel !== PHONE_OTP_CHANNEL_SMS && empty(System::getEnv('_APP_WHATSAPP_PROVIDER'))) {
            throw new Exception(Exception::PROJECT_PHONE_OTP_CHANNEL_UNAVAILABLE);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['phoneOtpChannel'] = $channel;

        $updates = new Document([
            'auths' => $auths,
        ]);

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'phone-otp-channel');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}
