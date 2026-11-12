<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\Events\RuleVerified;
use Appwrite\Event\Message\Notification as NotificationMessage;
use Appwrite\Event\Publisher\Notification as NotificationPublisher;
use Appwrite\Template\Template;
use Utopia\Bus\Listener;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

class Domains extends Listener
{
    public static function getName(): string
    {
        return 'domains';
    }

    public static function getEvents(): array
    {
        return [RuleVerified::class];
    }

    public function __construct()
    {
        $this
            ->desc('Tells project owners when their domain goes live')
            ->inject('dbForPlatform')
            ->inject('publisherForNotifications')
            ->inject('platform')
            ->inject('plan')
            ->callback($this->handle(...));
    }

    public function handle(RuleVerified $event, Database $dbForPlatform, NotificationPublisher $publisherForNotifications, array $platform, array $plan): void
    {
        $rule = new Document($event->rule);
        $project = new Document($event->project);

        $memberships = $dbForPlatform->find('memberships', [
            Query::equal('teamInternalId', [$project->getAttribute('teamInternalId')]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        $ownerMemberships = \array_filter(
            $memberships,
            fn (Document $membership) => $this->hasOwnerRole($membership)
        );

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
        $domain = $rule->getAttribute('domain', '');

        // Email domains get no certificate; they receive mail once verified
        $details = $rule->getAttribute('protocol', '') === 'smtp'
            ? 'It is ready to receive email.'
            : 'Its SSL certificate is issued, and it now serves HTTPS traffic.';

        // A site or function lists its own domains; API domains are in project settings
        $resourceId = $rule->getAttribute('deploymentResourceId', '');
        $path = match ($resourceId === '' ? '' : $rule->getAttribute('deploymentResourceType', '')) {
            'site' => "/projects/{$projectId}/sites/{$resourceId}/domains",
            'function' => "/projects/{$projectId}/functions/{$resourceId}/domains",
            default => "/projects/{$projectId}/settings/domains",
        };

        $subject = 'Your domain ' . $domain . ' is live';
        $preview = 'Domain "' . $domain . '" on project "' . $project->getAttribute('name') . '" is verified and live.';

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

            $template = Template::fromFile(__DIR__ . '/../../../../app/config/locale/templates/email-domain-verified.tpl');
            $template->setParam('{{user}}', (string) ($user->getAttribute('name', 'there') ?: 'there'));
            $template->setParam('{{domain}}', $domain);
            $template->setParam('{{project}}', $project->getAttribute('name'));
            $template->setParam('{{details}}', $details);
            $template->setParam('{{host}}', $platform['consoleUrl'] ?? '');
            $template->setParam('{{path}}', $path);

            $publisherForNotifications->enqueue(new NotificationMessage(
                project: $project,
                recipients: $recipients,
                template: MAIL_TEMPLATE_DOMAIN_VERIFIED,
                deduplicationKey: 'rule:' . $rule->getId() . ':verified:' . $rule->getUpdatedAt(),
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

    private function hasOwnerRole(Document $membership): bool
    {
        $roles = $membership->getAttribute('roles', []);
        if (\is_string($roles)) {
            $roles = \array_map('trim', \explode(',', $roles));
        }
        if (!\is_array($roles)) {
            return false;
        }

        foreach ($roles as $role) {
            if (\is_string($role) && \strtolower($role) === 'owner') {
                return true;
            }
        }

        return false;
    }
}
