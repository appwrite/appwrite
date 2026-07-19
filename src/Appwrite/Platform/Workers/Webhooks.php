<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Message\Notification as NotificationMessage;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Notification as NotificationPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Template\Template;
use Appwrite\Usage\Context as UsageContext;
use Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\System\System;

class Webhooks extends Action
{
    private const MAX_FILE_SIZE = 5242880; // 5 MB

    public static function getName(): string
    {
        return 'webhooks';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Webhooks worker')
            ->inject('message')
            ->inject('project')
            ->inject('dbForPlatform')
            ->inject('publisherForNotifications')
            ->inject('publisherForUsage')
            ->inject('log')
            ->inject('plan')
            ->callback($this->action(...));
    }

    /**
     * @param Message $message
     * @param Document $project
     * @param Database $dbForPlatform
     * @param NotificationPublisher $publisherForNotifications
     * @param UsagePublisher $publisherForUsage
     * @param Log $log
     * @param array $plan
     * @return void
     * @throws Exception
     */
    public function action(Message $message, Document $project, Database $dbForPlatform, NotificationPublisher $publisherForNotifications, UsagePublisher $publisherForUsage, Log $log, array $plan): void
    {
        $payload = $message->getPayload();

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $events = $payload['events'];
        $webhookPayload = json_encode($payload['payload']);
        $user = new Document($payload['user'] ?? []);

        $log->addTag('projectId', $project->getId());

        $errors = [];
        foreach ($project->getAttribute('webhooks', []) as $webhook) {
            if (array_intersect($webhook->getAttribute('events', []), $events)) {
                $error = $this->execute($events, $webhookPayload, $webhook, $user, $project, $dbForPlatform, $publisherForNotifications, $publisherForUsage, $plan);
                if ($error !== null) {
                    $errors[] = $error;
                }
            }
        }

        if (!empty($errors)) {
            throw new Exception(\implode(" / \n\n", $errors));
        }
    }

    /**
     * @param array $events
     * @param string $payload
     * @param Document $webhook
     * @param Document $user
     * @param Document $project
     * @param Database $dbForPlatform
     * @param NotificationPublisher $publisherForNotifications
     * @param UsagePublisher $publisherForUsage
     * @param array $plan
     * @return string|null The error log if the delivery failed, otherwise null
     */
    private function execute(array $events, string $payload, Document $webhook, Document $user, Document $project, Database $dbForPlatform, NotificationPublisher $publisherForNotifications, UsagePublisher $publisherForUsage, array $plan): ?string
    {
        if ($webhook->getAttribute('enabled') !== true) {
            return null;
        }

        $url = \rawurldecode($webhook->getAttribute('url'));

        if (System::getEnv('_APP_ENV', 'development') === 'production') {
            $host = \parse_url($url, PHP_URL_HOST) ?? '';
            $hostnameValidator = new PublicHostname();
            if (!$hostnameValidator->isValid($host)) {
                return 'Webhook target ' . $host . ' rejected: ' . $hostnameValidator->getDescription();
            }
        }

        $signatureKey = $webhook->getAttribute('signatureKey');
        $signature = base64_encode(hash_hmac('sha1', $url . $payload, $signatureKey, true));
        $httpUser = $webhook->getAttribute('httpUser');
        $httpPass = $webhook->getAttribute('httpPass');
        $ch = \curl_init($webhook->getAttribute('url'));

        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        \curl_setopt($ch, CURLOPT_HEADER, 0);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        \curl_setopt($ch, CURLOPT_MAXFILESIZE, self::MAX_FILE_SIZE);
        \curl_setopt($ch, CURLOPT_USERAGENT, \sprintf(
            APP_USERAGENT,
            System::getEnv('_APP_VERSION', 'UNKNOWN'),
            System::getEnv('_APP_EMAIL_SECURITY', System::getEnv('_APP_SYSTEM_SECURITY_EMAIL_ADDRESS', APP_EMAIL_SECURITY))
        ));
        \curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
                'Content-Type: application/json',
                'Content-Length: ' . \strlen($payload),
                'X-' . APP_NAME . '-Webhook-Id: ' . $webhook->getId(),
                'X-' . APP_NAME . '-Webhook-Events: ' . implode(',', $events),
                'X-' . APP_NAME . '-Webhook-Name: ' . $webhook->getAttribute('name', ''),
                'X-' . APP_NAME . '-Webhook-User-Id: ' . $user->getId(),
                'X-' . APP_NAME . '-Webhook-Project-Id: ' . $project->getId(),
                'X-' . APP_NAME . '-Webhook-Signature: ' . $signature,
            ]
        );
        \curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        if (!$webhook->getAttribute('security', true)) {
            \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        if (!empty($httpUser) && !empty($httpPass)) {
            \curl_setopt($ch, CURLOPT_USERPWD, "$httpUser:$httpPass");
            \curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        }

        $responseBody = \curl_exec($ch);
        $curlError = \curl_error($ch);
        $statusCode = \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $error = null;

        if (!empty($curlError) || $statusCode >= 400) {
            $dbForPlatform->increaseDocumentAttribute('webhooks', $webhook->getId(), 'attempts', 1);
            $webhook = $dbForPlatform->getDocument('webhooks', $webhook->getId());
            $attempts = $webhook->getAttribute('attempts');

            $logs = '';
            $logs .= 'URL: ' . $webhook->getAttribute('url') . "\n";
            $logs .= 'Method: ' . 'POST' . "\n";

            if (!empty($curlError)) {
                $logs .= 'CURL Error: ' . $curlError . "\n";
                $logs .= 'Events: ' . implode(', ', $events) . "\n";
            } else {
                $logs .= 'Status code: ' . $statusCode . "\n";
                $logs .= 'Body: ' . "\n" . \mb_strcut($responseBody, 0, 10000) . "\n"; // Limit to 10kb
            }

            $webhook->setAttribute('logs', $logs);

            $updatePayload = ['logs' => $logs];

            if ($attempts >= \intval(System::getEnv('_APP_WEBHOOK_MAX_FAILED_ATTEMPTS', '10'))) {
                $webhook->setAttribute('enabled', false);
                $updatePayload['enabled'] = false;
                $this->sendAlert($attempts, $statusCode, $webhook, $project, $dbForPlatform, $publisherForNotifications, $plan);
            }

            $dbForPlatform->updateDocument('webhooks', $webhook->getId(), new Document($updatePayload));
            $dbForPlatform->purgeCachedDocument('projects', $project->getId());

            $error = $logs;
            $usage = (new UsageContext())
                ->addMetric(METRIC_WEBHOOKS_FAILED, 1)
                ->addMetric(str_replace('{webhookInternalId}', $webhook->getSequence(), METRIC_WEBHOOK_ID_FAILED), 1);
        } else {
            if ($webhook->getAttribute('attempts', 0) > 0) {
                $dbForPlatform->updateDocument('webhooks', $webhook->getId(), new Document([
                    'attempts' => 0,
                ]));

                $dbForPlatform->purgeCachedDocument('projects', $project->getId());
            }

            $usage = (new UsageContext())
                ->addMetric(METRIC_WEBHOOKS_SENT, 1)
                ->addMetric(str_replace('{webhookInternalId}', $webhook->getSequence(), METRIC_WEBHOOK_ID_SENT), 1);
        }

        $publisherForUsage->enqueue(new UsageMessage(
            project: $project,
            metrics: $usage->getMetrics(),
        ));

        return $error;
    }

    /**
     * @param int $attempts
     * @param mixed $statusCode
     * @param Document $webhook
     * @param Document $project
     * @param Database $dbForPlatform
     * @param NotificationPublisher $publisherForNotifications
     * @param array $plan
     * @return void
     */
    public function sendAlert(int $attempts, mixed $statusCode, Document $webhook, Document $project, Database $dbForPlatform, NotificationPublisher $publisherForNotifications, array $plan): void
    {
        $memberships = $dbForPlatform->find('memberships', [
            Query::equal('teamInternalId', [$project->getAttribute('teamInternalId')]),
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        $ownerMemberships = \array_filter(
            $memberships,
            fn (Document $membership) => self::hasOwnerRole($membership)
        );

        if (empty($ownerMemberships)) {
            return;
        }

        $userIds = \array_values(\array_unique(\array_filter(\array_map(
            fn (Document $membership) => $membership->getAttribute('userId'),
            $ownerMemberships
        ))));

        if (empty($userIds)) {
            return;
        }

        $users = $dbForPlatform->find('users', [
            Query::equal('$id', $userIds),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        if (empty($users)) {
            return;
        }

        $projectId = $project->getId();
        $projectInternalId = $project->getSequence();
        $region = $project->getAttribute('region', 'default');
        $webhookId = $webhook->getId();

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'disabled' ? 'http' : 'https';
        $consoleHostname = System::getEnv('_APP_CONSOLE_DOMAIN', System::getEnv('_APP_DOMAIN', 'localhost'));

        $subject = 'Webhook deliveries have been paused';
        $preview = 'Webhook "' . $webhook->getAttribute('name') . '" has been paused after ' . $attempts . ' failed delivery attempts.';

        foreach ($users as $user) {
            $email = $user->getAttribute('email');
            $userId = $user->getId();

            $recipients = [[
                'address' => $userId,
                'channel' => NOTIFICATION_TYPE_CONSOLE,
                'resourceType' => RESOURCE_TYPE_USERS,
                'resourceId' => $userId,
                'resourceInternalId' => (string) $user->getSequence(),
                'parentResourceType' => RESOURCE_TYPE_PROJECTS,
                'parentResourceId' => $projectId,
                'parentResourceInternalId' => (string) $projectInternalId,
            ]];

            if (!empty($email)) {
                $recipients[] = [
                    'address' => $email,
                    'channel' => NOTIFICATION_TYPE_EMAIL,
                    'resourceType' => RESOURCE_TYPE_USERS,
                    'resourceId' => $userId,
                    'resourceInternalId' => (string) $user->getSequence(),
                    'parentResourceType' => RESOURCE_TYPE_PROJECTS,
                    'parentResourceId' => $projectId,
                    'parentResourceInternalId' => (string) $projectInternalId,
                ];
            }

            $template = Template::fromFile(__DIR__ . '/../../../../app/config/locale/templates/email-webhook-failed.tpl');
            $userName = (string) ($user->getAttribute('name', 'there') ?: 'there');
            $template->setParam('{{user}}', $userName);
            $template->setParam('{{webhook}}', $webhook->getAttribute('name'));
            $template->setParam('{{project}}', $project->getAttribute('name'));
            $template->setParam('{{url}}', $webhook->getAttribute('url'));
            $template->setParam('{{error}}', 'The server returned ' . $statusCode . ' status code');
            $template->setParam('{{host}}', $protocol . '://' . $consoleHostname);
            $template->setParam('{{path}}', System::getEnv('_APP_CONSOLE_URL_SCHEME', 'legacy') !== 'root'
                ? "/console/project-{$region}-{$projectId}/settings/webhooks/{$webhookId}"
                : "/projects/{$projectId}/settings/webhooks");
            $template->setParam('{{attempts}}', $attempts);

            $publisherForNotifications->enqueue(new NotificationMessage(
                project: $project,
                recipients: $recipients,
                deduplicationKey: 'webhook:' . $webhook->getId() . ':paused:' . $webhook->getUpdatedAt(),
                subject: $subject,
                bodyTemplate: __DIR__ . '/../../../../app/config/locale/templates/email-base-styled.tpl',
                body: $template->render(),
                preview: $preview,
                variables: [
                    'logoUrl' => $plan['logoUrl'] ?? APP_EMAIL_LOGO_URL,
                    'accentColor' => $plan['accentColor'] ?? APP_EMAIL_ACCENT_COLOR,
                    'twitter' => $plan['twitterUrl'] ?? APP_SOCIAL_TWITTER,
                    'discord' => $plan['discordUrl'] ?? APP_SOCIAL_DISCORD,
                    'github' => $plan['githubUrl'] ?? APP_SOCIAL_GITHUB_APPWRITE,
                    'terms' => $plan['termsUrl'] ?? APP_EMAIL_TERMS_URL,
                    'privacy' => $plan['privacyUrl'] ?? APP_EMAIL_PRIVACY_URL,
                    'platform' => $plan['platformName'] ?? APP_NAME,
                ],
            ));
        }
    }

    private static function hasOwnerRole(Document $membership): bool
    {
        $roles = $membership->getAttribute('roles', []);
        if (\is_string($roles)) {
            $roles = \array_map('trim', \explode(',', $roles));
        }
        if (!\is_array($roles)) {
            return false;
        }

        foreach ($roles as $role) {
            if (!\is_string($role)) {
                continue;
            }

            if (\strtolower($role) === 'owner') {
                return true;
            }
        }

        return false;
    }
}
