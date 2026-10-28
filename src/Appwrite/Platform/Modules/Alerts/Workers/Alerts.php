<?php

namespace Appwrite\Platform\Modules\Alerts\Workers;

use Appwrite\Alert\Channel;
use Appwrite\Alert\Comparator;
use Appwrite\Alert\Meter;
use Appwrite\Alert\Status;
use Appwrite\Event\Message\Alert as AlertMessage;
use Appwrite\Event\Message\Notification as NotificationMessage;
use Appwrite\Event\Publisher\Notification as NotificationPublisher;
use Appwrite\Platform\Action;
use Appwrite\Template\Template;
use Appwrite\Usage\Connection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\Usage\Tenant;

/**
 * Measures one alert rule, compares it with its threshold and records the
 * result. A notification goes out only when the rule changes state: entering
 * `firing` (at most once per cooldown) and returning to `ok`.
 */
class Alerts extends Action
{
    public static function getName(): string
    {
        return 'alerts';
    }

    public function __construct()
    {
        $this
            ->desc('Alerts worker')
            ->inject('message')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('usageConnection')
            ->inject('publisherForNotifications')
            ->inject('platform')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $platform
     */
    public function action(
        Message $message,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Connection $usageConnection,
        NotificationPublisher $publisherForNotifications,
        array $platform,
    ): void {
        $ruleId = AlertMessage::fromArray($message->getPayload())->ruleId;
        Span::add('rule.id', $ruleId);

        // The project was deleted after the evaluation was scheduled; its rules go with it.
        if ($project->isEmpty()) {
            return;
        }

        $rule = $dbForPlatform->getDocument('alertRules', $ruleId);
        if ($rule->isEmpty() || !$rule->getAttribute('enabled', false) || $rule->getAttribute('projectInternalId') !== (string) $project->getSequence()) {
            return;
        }

        if (!$usageConnection->isEnabled()) {
            return;
        }
        if (!$usageConnection->isReady()) {
            throw new \RuntimeException('Usage schema is not ready');
        }

        $now = new \DateTimeImmutable();
        $value = $this->measure($rule, $project, $dbForProject, $usageConnection, $now);

        $matches = Comparator::from((string) $rule->getAttribute('comparator'))->matches($value, (float) $rule->getAttribute('threshold'));
        $previous = Status::tryFrom((string) $rule->getAttribute('status')) ?? Status::Ok;
        $status = $matches ? Status::Firing : Status::Ok;
        $evaluatedAt = DateTime::format(\DateTime::createFromImmutable($now));

        Span::add('rule.metric', (string) $rule->getAttribute('metric'));
        Span::add('rule.value', $value);
        Span::add('rule.status', $status->value);

        $changes = new Document([
            'value' => $value,
            'evaluatedAt' => $evaluatedAt,
        ]);

        $notify = false;
        if ($status !== $previous) {
            $changes->setAttribute('status', $status->value);

            if ($status === Status::Firing) {
                // Cooldown counts from the last firing that was notified, so a
                // rule flapping around its threshold is reported once per cooldown.
                $triggeredAt = $rule->getAttribute('triggeredAt');
                $cooled = $triggeredAt === null
                    || $now->getTimestamp() - (new \DateTimeImmutable($triggeredAt))->getTimestamp() >= (int) $rule->getAttribute('cooldown');

                if ($cooled) {
                    $changes->setAttribute('triggeredAt', $evaluatedAt);
                    $notify = true;
                }
            } else {
                $notify = true;
            }
        }

        // Publish before the new state is stored: if publishing fails, the retry
        // still sees the transition instead of finding it already recorded.
        if ($notify) {
            Span::add('rule.notified', true);
            $this->notify($rule, $project, $status, $value, $evaluatedAt, $dbForPlatform, $publisherForNotifications, $platform);
        }

        $dbForPlatform->updateDocument('alertRules', $rule->getId(), $changes);
    }

    /**
     * The value the rule compares with its threshold. Editions that register
     * metrics this meter does not read override this with their own meter.
     */
    protected function measure(Document $rule, Document $project, Database $dbForProject, Connection $usageConnection, \DateTimeImmutable $now): float
    {
        $databaseType = '';
        if ($rule->getAttribute('resourceType') === 'database') {
            $databaseType = (string) $dbForProject->getDocument('databases', (string) $rule->getAttribute('resourceId'))->getAttribute('type', '');
        }

        $meter = new Meter(new Tenant($usageConnection->getUsage(), (string) $project->getSequence()));

        return $meter->measure($rule, $now, $databaseType);
    }

    /**
     * Console path the notification links to, appended to the console URL.
     */
    protected function path(Document $project): string
    {
        return '/projects/' . $project->getId() . '/settings/alerts';
    }

    /**
     * Publishes a state change: one message for the rule's webhook, and one per
     * organization owner for the console bell and email.
     *
     * The deduplication key names the rule, its new status and the moment it
     * changed, so an at-least-once redelivery of the same evaluation sends
     * nothing twice while the next transition always gets through.
     *
     * @param array<string, mixed> $platform
     */
    protected function notify(
        Document $rule,
        Document $project,
        Status $status,
        float $value,
        string $changedAt,
        Database $dbForPlatform,
        NotificationPublisher $publisherForNotifications,
        array $platform,
    ): void {
        $channels = $rule->getAttribute('channels', []);
        $deduplicationKey = 'alert:' . $rule->getId() . ':' . $status->value . ':' . $changedAt;
        $firing = $status === Status::Firing;
        $metric = (string) $rule->getAttribute('metric');
        $parent = [
            'parentResourceType' => RESOURCE_TYPE_PROJECTS,
            'parentResourceId' => $project->getId(),
            'parentResourceInternalId' => (string) $project->getSequence(),
        ];

        $shown = $this->number($value);
        $condition = Comparator::from((string) $rule->getAttribute('comparator'))->symbol() . ' ' . $this->number((float) $rule->getAttribute('threshold'));
        $subject = ($firing ? 'Alert firing: ' : 'Alert resolved: ') . $rule->getAttribute('name');
        $summary = $firing
            ? "{$metric} is {$shown}, which is {$condition}, in project {$project->getAttribute('name')}."
            : "{$metric} is back to {$shown} in project {$project->getAttribute('name')}.";

        if (\in_array(Channel::Webhook->value, $channels, true) && $rule->getAttribute('webhookUrl', '') !== '') {
            $this->publish($publisherForNotifications, new NotificationMessage(
                project: $project,
                recipients: [[
                    'address' => (string) $rule->getAttribute('webhookUrl'),
                    'channel' => NOTIFICATION_TYPE_WEBHOOK,
                    'signatureKey' => (string) $rule->getAttribute('webhookSecret', ''),
                    'resourceType' => RESOURCE_TYPE_ALERT_RULES,
                    'resourceId' => $rule->getId(),
                    'resourceInternalId' => (string) $rule->getSequence(),
                    ...$parent,
                ]],
                templateParams: [
                    'ruleId' => $rule->getId(),
                    'ruleName' => $rule->getAttribute('name'),
                    'status' => $status->value,
                    'projectId' => $project->getId(),
                    'resourceType' => $rule->getAttribute('resourceType'),
                    'resourceId' => $rule->getAttribute('resourceId'),
                    'metric' => $metric,
                    'comparator' => $rule->getAttribute('comparator'),
                    'threshold' => (float) $rule->getAttribute('threshold'),
                    'window' => $rule->getAttribute('window'),
                    'value' => $value,
                    'changedAt' => (new \DateTimeImmutable($changedAt))->format(\DateTimeInterface::RFC3339_EXTENDED),
                ],
                deduplicationKey: $deduplicationKey,
                subject: $subject,
                body: $summary,
                events: ['alerts.' . $status->value],
            ));
        }

        $email = \in_array(Channel::Email->value, $channels, true);
        $console = \in_array(Channel::Console->value, $channels, true);
        if (!$email && !$console) {
            return;
        }

        foreach ($this->owners($dbForPlatform, (string) $rule->getAttribute('teamInternalId')) as $user) {
            $resource = [
                'resourceType' => RESOURCE_TYPE_USERS,
                'resourceId' => $user->getId(),
                'resourceInternalId' => (string) $user->getSequence(),
                ...$parent,
            ];

            $recipients = [];
            if ($console) {
                $recipients[] = ['address' => $user->getId(), 'channel' => NOTIFICATION_TYPE_CONSOLE, ...$resource];
            }
            if ($email && (string) $user->getAttribute('email', '') !== '') {
                $recipients[] = ['address' => (string) $user->getAttribute('email'), 'channel' => NOTIFICATION_TYPE_EMAIL, ...$resource];
            }
            if ($recipients === []) {
                continue;
            }

            $template = Template::fromFile(APP_CE_CONFIG_DIR . '/locale/templates/email-alert.tpl')
                ->setParam('{{user}}', (string) ($user->getAttribute('name', '') ?: 'there'))
                ->setParam('{{summary}}', $summary)
                ->setParam('{{rule}}', (string) $rule->getAttribute('name'))
                ->setParam('{{project}}', (string) $project->getAttribute('name'))
                ->setParam('{{metric}}', $metric)
                ->setParam('{{value}}', $shown)
                ->setParam('{{condition}}', $condition)
                ->setParam('{{host}}', (string) ($platform['consoleUrl'] ?? ''))
                ->setParam('{{path}}', $this->path($project));

            $this->publish($publisherForNotifications, new NotificationMessage(
                project: $project,
                recipients: $recipients,
                template: MAIL_TEMPLATE_ALERT,
                deduplicationKey: $deduplicationKey,
                subject: $subject,
                bodyTemplate: APP_CE_CONFIG_DIR . '/locale/templates/email-base-styled.tpl',
                body: $template->render(),
                preview: $summary,
                variables: [
                    'logoUrl' => $platform['logoUrl'] ?? APP_EMAIL_LOGO_URL,
                    'accentColor' => $platform['accentColor'] ?? APP_EMAIL_ACCENT_COLOR,
                    'twitter' => $platform['twitterUrl'] ?? APP_SOCIAL_TWITTER,
                    'discord' => $platform['discordUrl'] ?? APP_SOCIAL_DISCORD,
                    'github' => $platform['githubUrl'] ?? APP_SOCIAL_GITHUB_APPWRITE,
                    'terms' => $platform['termsUrl'] ?? APP_EMAIL_TERMS_URL,
                    'privacy' => $platform['privacyUrl'] ?? APP_EMAIL_PRIVACY_URL,
                    'platform' => $platform['platformName'] ?? APP_NAME,
                ],
            ));
        }
    }

    /**
     * The organization's confirmed owners.
     *
     * @return list<Document>
     */
    private function owners(Database $dbForPlatform, string $teamInternalId): array
    {
        $userIds = [];
        $cursor = null;
        do {
            $queries = [
                Query::equal('teamInternalId', [$teamInternalId]),
                Query::equal('confirm', [true]),
                Query::orderAsc('$sequence'),
                Query::limit(APP_LIMIT_SUBQUERY),
            ];
            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }

            $memberships = $dbForPlatform->find('memberships', $queries);
            foreach ($memberships as $membership) {
                if (\in_array('owner', $membership->getAttribute('roles', []), true)) {
                    $userIds[] = (string) $membership->getAttribute('userId');
                }
            }

            $cursor = \end($memberships) ?: null;
        } while (\count($memberships) === APP_LIMIT_SUBQUERY);

        $userIds = \array_values(\array_unique($userIds));
        if ($userIds === []) {
            return [];
        }

        return $dbForPlatform->find('users', [
            Query::equal('$id', $userIds),
            Query::limit(\count($userIds)),
        ]);
    }

    private function number(float $value): string
    {
        return \rtrim(\rtrim(\number_format($value, 2, '.', ','), '0'), '.');
    }

    /**
     * A message the broker did not accept fails the evaluation, so the rule's new
     * status is not stored and the next evaluation sees the transition again.
     */
    protected function publish(NotificationPublisher $publisherForNotifications, NotificationMessage $message): void
    {
        if ($publisherForNotifications->enqueue($message) === false) {
            throw new \RuntimeException('The notification for the alert rule was not accepted by the queue.');
        }
    }
}
