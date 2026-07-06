<?php

namespace Appwrite\Platform\Modules\Notifications\Http\Notifications\Logos\Appwrite;

use Ahc\Jwt\JWT;
use Appwrite\Event\Message\Audit as AuditMessage;
use Appwrite\Event\Publisher\Audit as AuditPublisher;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getNotificationLogo';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/notifications/logos/appwrite')
            ->desc('Get notification logo')
            ->groups(['api', 'notifications'])
            ->label('scope', 'public')
            ->label('sdk', new Method(
                namespace: 'notifications',
                group: 'logos',
                name: 'getLogo',
                description: '/docs/references/notifications/get-logo.md',
                auth: [],
                type: MethodType::LOCATION,
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_NONE,
                    ),
                ],
                contentType: ContentType::IMAGE_SVG,
            ))
            ->param('jwt', '', new Text(2048, 0), 'Tracking token.', true)
            ->param('theme', 'system', new WhiteList(['system', 'light', 'dark']), 'Logo color theme.', true)
            ->inject('request')
            ->inject('userAgent')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('publisherForAudits')
            ->callback($this->action(...));
    }

    public function action(
        string $jwt,
        string $theme,
        Request $request,
        string $userAgent,
        Response $response,
        Database $dbForPlatform,
        Authorization $authorization,
        AuditPublisher $publisherForAudits,
    ): void {
        $secret = System::getEnv('_APP_NOTIFICATIONS_TRACKING_SECRET');

        if (!empty($secret) && $jwt !== '') {
            try {
                $decoder = new JWT($secret, 'HS256', NOTIFICATION_TRACKING_JWT_TTL, 0);
                $decoded = $decoder->decode($jwt);

                $result = $authorization->skip(fn () => $this->trackView($decoded, $dbForPlatform));

                if ($result !== null) {
                    $this->logView(
                        $result['notification'],
                        $result['project'],
                        $result['seenAt'],
                        $request,
                        $userAgent,
                        $publisherForAudits,
                    );
                }
            } catch (\Throwable) {
                // Always return the logo and avoid exposing token validity.
            }
        }

        $response
            ->setContentType('image/svg+xml')
            ->addHeader('Cache-Control', 'no-store')
            ->send($this->renderLogo($theme));
    }

    /**
     * @return array{notification: Document, project: Document, seenAt: string}|null
     */
    private function trackView(array $decoded, Database $dbForPlatform): ?array
    {
        if (
            !isset($decoded['messageId'], $decoded['recipientHash'], $decoded['channel'], $decoded['projectId'], $decoded['purpose'])
            || $decoded['purpose'] !== 'notification_track'
        ) {
            return null;
        }

        $notifications = $dbForPlatform->find('notifications', [
            Query::equal('messageId', [(string) $decoded['messageId']]),
            Query::equal('channel', [(string) $decoded['channel']]),
            Query::equal('recipientHash', [(string) $decoded['recipientHash']]),
            Query::limit(1),
        ]);

        if (empty($notifications)) {
            return null;
        }

        $notification = $notifications[0];
        if (
            $notification->getAttribute('projectId') !== (string) $decoded['projectId']
            || (isset($decoded['projectInternalId']) && $notification->getAttribute('projectInternalId') !== (string) $decoded['projectInternalId'])
        ) {
            return null;
        }

        $seenAt = DateTime::now();
        $updates = [
            'read' => true,
            'lastSeen' => $seenAt,
        ];

        if (empty($notification->getAttribute('firstSeen'))) {
            $updates['firstSeen'] = $seenAt;
        }

        $updated = $dbForPlatform->updateDocument('notifications', $notification->getId(), new Document($updates));
        $project = $dbForPlatform->getDocument('projects', $notification->getAttribute('projectId'));

        if ($project->isEmpty()) {
            $project = new Document([
                '$id' => 'console',
                '$sequence' => 'console',
            ]);
        }

        return [
            'notification' => $updated,
            'project' => $project,
            'seenAt' => $seenAt,
        ];
    }

    private function logView(Document $notification, Document $project, string $seenAt, Request $request, string $userAgent, AuditPublisher $publisherForAudits): void
    {
        $publisherForAudits->enqueue(new AuditMessage(
            event: 'notification.view',
            payload: [
                'notificationId' => $notification->getId(),
                'messageId' => $notification->getAttribute('messageId', ''),
                'channel' => $notification->getAttribute('channel', ''),
                'projectId' => $notification->getAttribute('projectId', ''),
                'firstSeen' => $notification->getAttribute('firstSeen'),
                'lastSeen' => $notification->getAttribute('lastSeen'),
                'seenAt' => $seenAt,
            ],
            project: $project,
            user: new Document([
                '$id' => $notification->getAttribute('resourceId', ''),
                '$sequence' => $notification->getAttribute('resourceInternalId', ''),
                'name' => '',
                'email' => '',
                'type' => ACTOR_TYPE_HIDDEN,
            ]),
            resource: 'notification/' . $notification->getId(),
            mode: APP_MODE_DEFAULT,
            ip: $request->getIP(),
            userAgent: $userAgent,
            hostname: $request->getHostname(),
        ));
    }

    private function renderLogo(string $theme): string
    {
        $style = match ($theme) {
            'light' => '.wordmark { fill: #19191C; }',
            'dark' => '.wordmark { fill: #F5F5F7; }',
            default => '.wordmark { fill: #19191C; } @media (prefers-color-scheme: dark) { .wordmark { fill: #F5F5F7; } }',
        };

        return <<<SVG
<svg width="120" height="28" viewBox="0 0 120 28" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Appwrite">
  <style>{$style}</style>
  <path d="M24.4429 17.4322V22.9096H10.7519C6.76318 22.9096 3.28044 20.7067 1.4171 17.4322C1.14622 16.9561 0.909137 16.4567 0.710264 15.9383C0.319864 14.9225 0.0744552 13.8325 0 12.6952V11.2143C0.0161646 10.9609 0.0416361 10.7094 0.0749451 10.4609C0.143032 9.95105 0.245898 9.45211 0.381093 8.96711C1.66006 4.36909 5.81877 1 10.7519 1C15.6851 1 19.8433 4.36909 21.1223 8.96711H15.2682C14.3072 7.4683 12.6437 6.4774 10.7519 6.4774C8.86017 6.4774 7.19668 7.4683 6.23562 8.96711C5.9427 9.42274 5.71542 9.92516 5.56651 10.4609C5.43425 10.936 5.36371 11.4369 5.36371 11.9548C5.36371 13.5248 6.01324 14.94 7.05463 15.9383C8.01961 16.865 9.32061 17.4322 10.7519 17.4322H24.4429Z" fill="#FD366E"/>
  <path d="M24.4429 10.4609V15.9383H14.4492C15.4906 14.94 16.1401 13.5248 16.1401 11.9548C16.1401 11.4369 16.0696 10.936 15.9373 10.4609H24.4429Z" fill="#FD366E"/>
  <text class="wordmark" x="34" y="19" font-family="Arial, Helvetica, sans-serif" font-size="16" font-weight="700">Appwrite</text>
</svg>
SVG;
    }
}
