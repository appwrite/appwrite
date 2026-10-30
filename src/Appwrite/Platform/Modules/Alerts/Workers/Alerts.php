<?php

namespace Appwrite\Platform\Modules\Alerts\Workers;

use Appwrite\Alert\Comparator;
use Appwrite\Alert\Email;
use Appwrite\Alert\Meter;
use Appwrite\Alert\Quantity;
use Appwrite\Alert\Schedules;
use Appwrite\Alert\State;
use Appwrite\Alert\Status;
use Appwrite\Alert\Unsubscribe;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Alert as AlertMessage;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Message\Notification as NotificationMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Notification as NotificationPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Action;
use Appwrite\Usage\Connection;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response\Model\Alert as AlertModel;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Queue\Message;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Usage\Tenant;

/**
 * Measures one monitor, compares it with its threshold and records the result
 * on the monitor and on the alert it opens while firing. Every change of state
 * fires a project event (`monitors.[monitorId].alerts.[alertId].firing` or
 * `.resolved`) for webhooks, functions and realtime. The organization's members
 * holding a role its `notify` names are notified by email and in the console:
 * on entering `firing` (at most once per cooldown) and on returning to `ok`.
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
            ->inject('queueForEvents')
            ->inject('queueForWebhooks')
            ->inject('publisherForFunctions')
            ->inject('queueForRealtime')
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
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        array $platform,
    ): void {
        $monitorId = AlertMessage::fromArray($message->getPayload())->monitorId;
        Span::add('monitor.id', $monitorId);

        // The project was deleted after the evaluation was scheduled; its monitors go with it.
        if ($project->isEmpty()) {
            return;
        }

        $monitor = $dbForPlatform->getDocument('monitors', $monitorId);
        if ($monitor->isEmpty()) {
            // A schedule that outlived its monitor stops here, and the schedules sweep removes it.
            (new Schedules($dbForPlatform))->stop(new Document([
                '$id' => $monitorId,
                'region' => (string) $project->getAttribute('region', ''),
                'projectId' => $project->getId(),
            ]));

            return;
        }
        if (!$monitor->getAttribute('enabled', false) || $monitor->getAttribute('projectInternalId') !== (string) $project->getSequence()) {
            return;
        }

        if (!$usageConnection->isEnabled()) {
            return;
        }
        if (!$usageConnection->isReady()) {
            throw new \RuntimeException('Usage schema is not ready');
        }

        $now = new \DateTimeImmutable();
        $value = $this->measure($monitor, $project, $dbForProject, $usageConnection, $now);
        if ($value === null) {
            // Nothing recorded to compare: an idle project or a stalled usage pipeline is
            // neither a breach nor a recovery, so the monitor keeps its status and alert,
            // and shows it was checked without a reading.
            Span::add('monitor.noData', true);
            $dbForPlatform->updateDocument('monitors', $monitor->getId(), new Document([
                'value' => null,
                'evaluatedAt' => DateTime::format(\DateTime::createFromImmutable($now)),
            ]));

            return;
        }

        $this->evaluate($monitor, $project, $value, $now, $dbForPlatform, $publisherForNotifications, $queueForEvents, $queueForWebhooks, $publisherForFunctions, $queueForRealtime, $platform, $dbForProject);
    }

    /**
     * Compares a measured value with the monitor's rules, keeps its alert and
     * announces it. Entering `firing` opens an alert and recovering resolves it;
     * each fires its project event. The team is notified once, as soon as the
     * cooldown since the last notification allows (on entering firing, or later
     * while it keeps firing), and of the resolution only if of the firing.
     * Editions that measure monitors outside a project's usage call this with their value.
     *
     * @param array<string, mixed> $platform
     */
    protected function evaluate(
        Document $monitor,
        Document $project,
        float $value,
        \DateTimeImmutable $now,
        Database $dbForPlatform,
        NotificationPublisher $publisherForNotifications,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
        array $platform,
        ?Database $dbForProject = null,
    ): void {
        $comparator = Comparator::from((string) $monitor->getAttribute('comparator'));
        $matches = $comparator->matches($value, (float) $monitor->getAttribute('threshold'));
        $previous = Status::tryFrom((string) $monitor->getAttribute('status')) ?? Status::Ok;
        $status = $matches ? Status::Firing : Status::Ok;
        $evaluatedAt = DateTime::format(\DateTime::createFromImmutable($now));

        Span::add('monitor.metric', (string) $monitor->getAttribute('metric'));
        Span::add('monitor.value', $value);
        Span::add('monitor.status', $status->value);

        $changes = new Document([
            'value' => $value,
            'evaluatedAt' => $evaluatedAt,
        ]);

        $announced = (bool) $monitor->getAttribute('announced', false);
        if ($status !== $previous) {
            $changes->setAttribute('status', $status->value);
        }

        $notify = false;
        // A monitor that notifies no role announces nothing, so it starts no cooldown; its events still fire.
        $silent = $monitor->getAttribute('notify', [User::ROLE_OWNER]) === [];
        if ($status === Status::Firing && !$announced && !$silent) {
            // Cooldown counts from the last announced firing, so a monitor flapping
            // around its threshold is reported once per cooldown, and a firing
            // that started inside the cooldown is reported once it has passed.
            $triggeredAt = $monitor->getAttribute('triggeredAt');
            $cooled = $triggeredAt === null
                || $now->getTimestamp() - (new \DateTimeImmutable($triggeredAt))->getTimestamp() >= (int) $monitor->getAttribute('cooldown');

            if ($cooled) {
                $changes->setAttribute('triggeredAt', $evaluatedAt);
                $changes->setAttribute('announced', true);
                $notify = true;
            }
        } elseif ($status === Status::Ok && $previous === Status::Firing) {
            $changes->setAttribute('announced', false);
            $notify = $announced;
        }

        // The alert is opened before anything is announced, so the announcement
        // can link to it. A retry, or an overlapping evaluation, opens the same one.
        if ($status === Status::Firing) {
            $alert = $this->open($monitor, $project, $value, $comparator, $dbForPlatform);
            // Overlapping evaluations already opened and resolved this firing after this one read
            // the monitor; its state is stale, so the next evaluation decides from theirs.
            if ($alert->getAttribute('status') !== State::Firing->value) {
                return;
            }
            $changes->setAttribute('alertId', $alert->getId());
        } else {
            // Looked up by monitor rather than by `alertId`: an evaluation that opened an
            // alert but failed to announce it never stored it on the monitor.
            $alert = $dbForPlatform->findOne('monitorAlerts', [
                Query::equal('monitorInternalId', [(string) $monitor->getSequence()]),
                Query::equal('status', [State::Firing->value]),
            ]);
            if ($previous === Status::Firing) {
                $changes->setAttribute('alertId', '');
            }
        }

        $reading = $alert->isEmpty() ? new Document() : $this->record($alert, $status, $value, $comparator, $notify, $evaluatedAt);

        // Publish before the new state is stored: if publishing fails, the retry
        // still sees the transition instead of finding it already recorded.
        if ($notify) {
            Span::add('monitor.notified', true);
            $this->notify($monitor, $alert, $project, $status, $value, $evaluatedAt, $dbForPlatform, $publisherForNotifications, $platform, $dbForProject);
        }

        // Every change of state is an event, whatever the cooldown holds back from the team.
        // Delivery is at least once: the new state is stored only after every queue
        // accepted the event, so a failed publish is retried rather than lost, and a
        // receiver tells a repeat by the alert's `$id` and `status`.
        if (!$alert->isEmpty() && $status !== $previous) {
            $this->trigger(
                $status === Status::Firing ? State::Firing : State::Resolved,
                // The alert as it stands once this reading is stored, which happens after publishing.
                new Document(\array_merge($alert->getArrayCopy(), $reading->getArrayCopy(), ['$updatedAt' => $evaluatedAt])),
                $project,
                $queueForEvents,
                $queueForWebhooks,
                $publisherForFunctions,
                $queueForRealtime,
            );
        }

        if (!$reading->isEmpty()) {
            $dbForPlatform->updateDocument('monitorAlerts', $alert->getId(), $reading);
        }

        $dbForPlatform->updateDocument('monitors', $monitor->getId(), $changes);
    }

    /**
     * The monitor's open alert, created when it starts firing. Its ID is derived
     * from the monitor's state it opens from, so evaluations that overlap or retry
     * from that state open the same alert: the first creates it, the rest reuse it,
     * or find it already resolved when they read the monitor before it recovered.
     * It belongs to the organization that owns the project when it opens.
     */
    protected function open(Document $monitor, Document $project, float $value, Comparator $comparator, Database $dbForPlatform): Document
    {
        $alert = $dbForPlatform->getDocument('monitorAlerts', (string) $monitor->getAttribute('alertId', ''));
        if (!$alert->isEmpty() && $alert->getAttribute('status') === State::Firing->value) {
            return $alert;
        }

        $alertId = \md5($monitor->getSequence() . ':' . ($monitor->getAttribute('evaluatedAt') ?? $monitor->getCreatedAt()));

        try {
            return $dbForPlatform->createDocument('monitorAlerts', new Document([
                '$id' => $alertId,
                '$permissions' => [],
                'name' => (string) $monitor->getAttribute('name'),
                'monitorId' => $monitor->getId(),
                'monitorInternalId' => (string) $monitor->getSequence(),
                'teamId' => (string) $project->getAttribute('teamId', ''),
                'teamInternalId' => (string) $project->getAttribute('teamInternalId', ''),
                'projectId' => (string) $monitor->getAttribute('projectId', ''),
                // An ID column, which takes no empty string for a monitor without a project.
                'projectInternalId' => $monitor->getAttribute('projectInternalId') ?: null,
                'region' => (string) $monitor->getAttribute('region', ''),
                'status' => State::Firing->value,
                'rules' => $monitor->getAttribute('rules', []),
                'type' => (string) $monitor->getAttribute('type'),
                'resourceType' => (string) $monitor->getAttribute('resourceType'),
                'resourceId' => (string) $monitor->getAttribute('resourceId'),
                'metric' => (string) $monitor->getAttribute('metric'),
                'comparator' => $comparator->value,
                'threshold' => (float) $monitor->getAttribute('threshold'),
                'value' => $value,
                'peak' => $value,
                'notifications' => 0,
                'search' => \implode(' ', \array_filter([$alertId, $monitor->getId(), (string) $monitor->getAttribute('name'), (string) $monitor->getAttribute('metric')])),
            ]));
        } catch (Duplicate) {
            return $dbForPlatform->getDocument('monitorAlerts', $alertId);
        }
    }

    /**
     * The reading to store on the monitor's alert: the latest value, the one
     * furthest past the threshold, how often the team was notified, and its
     * resolution once the monitor recovers. Empty for an alert already resolved.
     */
    protected function record(Document $alert, Status $status, float $value, Comparator $comparator, bool $announced, string $evaluatedAt): Document
    {
        if ($alert->getAttribute('status') !== State::Firing->value) {
            return new Document();
        }

        $changes = new Document(['value' => $value]);
        $peak = (float) $alert->getAttribute('peak', $value);
        $worse = \in_array($comparator, [Comparator::LessThan, Comparator::LessThanEqual], true) ? $value < $peak : $value > $peak;
        if ($status === Status::Firing && $worse) {
            $changes->setAttribute('peak', $value);
        }
        if ($status === Status::Firing && $announced) {
            $changes->setAttribute('notifications', (int) $alert->getAttribute('notifications', 0) + 1);
        }
        if ($status === Status::Ok) {
            $changes->setAttribute('status', State::Resolved->value);
            $changes->setAttribute('resolvedAt', $evaluatedAt);
        }

        return $changes;
    }

    /**
     * Fires the alert's project event, `monitors.[monitorId].alerts.[alertId].firing`
     * or `.resolved`, with the alert as its payload: to the project's webhooks,
     * the functions that listen to it, and realtime. A monitor outside a project,
     * which has no webhooks or functions, fires none.
     */
    protected function trigger(
        State $state,
        Document $alert,
        Document $project,
        Event $queueForEvents,
        Webhook $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        Realtime $queueForRealtime,
    ): void {
        if ($project->isEmpty() || $project->getId() === 'console' || (string) $alert->getAttribute('projectId', '') !== $project->getId()) {
            return;
        }

        $queueForEvents
            ->setProject($project)
            ->setEvent('monitors.[monitorId].alerts.[alertId].' . $state->value)
            ->setParam('monitorId', (string) $alert->getAttribute('monitorId'))
            ->setParam('alertId', $alert->getId())
            ->setPayload((new AlertModel())->payload($alert));

        if ($queueForWebhooks->from($queueForEvents)->trigger() === false) {
            throw new \RuntimeException('The alert event for webhooks was not accepted by the queue.');
        }

        $function = $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
            event: $queueForEvents->getEvent(),
            params: $queueForEvents->getParams(),
            project: $queueForEvents->getProject(),
            payload: $queueForEvents->getPayload(),
        ));
        if ($function === false) {
            throw new \RuntimeException('The alert event for functions was not accepted by the queue.');
        }

        $queueForRealtime
            ->setSubscribers(['console', $project->getId()])
            ->from($queueForEvents)
            ->trigger();
    }

    /**
     * The value the monitor compares with its threshold, or null when there is no
     * usage to read. Editions that register metrics this meter does not read
     * override this with their own meter.
     */
    protected function measure(Document $monitor, Document $project, Database $dbForProject, Connection $usageConnection, \DateTimeImmutable $now): ?float
    {
        // A resource whose usage is recorded under its engine's name, such as a DocumentsDB database.
        $resource = Config::getParam('alerts', [])['resources'][(string) $monitor->getAttribute('resourceType')] ?? [];
        $databaseType = '';
        if (isset($resource['engine'], $resource['collection'])) {
            $databaseType = (string) $dbForProject->getDocument($resource['collection'], (string) $monitor->getAttribute('resourceId'))->getAttribute($resource['engine'], '');
        }

        // Unset or empty reads the default; 0 reads usage up to now.
        $lag = System::getEnv('_APP_ALERTS_USAGE_LAG', '');
        $meter = new Meter(
            new Tenant($usageConnection->getUsage(), (string) $project->getSequence()),
            lag: \is_numeric($lag) ? (int) $lag : ALERTS_USAGE_LAG_SECONDS,
        );

        return $meter->measure($monitor, $now, $databaseType);
    }

    /**
     * Console path of the project's usage pages; the notification links to its alerts and monitors pages.
     */
    protected function path(Document $project): string
    {
        return '/projects/' . $project->getId() . '/usage';
    }

    /**
     * Notifies the team of the monitor's alert firing or resolving: one message
     * per confirmed member of the project's organization holding a role the
     * monitor's `notify` names, for the console bell and, unless they stopped
     * this monitor's or all alert emails, by email. Each links to the alert and
     * carries one-click unsubscribe links signed for its recipient.
     *
     * The deduplication key names the monitor, its new status and the moment it
     * changed, so an at-least-once redelivery of the same evaluation sends
     * nothing twice while the next transition always gets through.
     *
     * @param array<string, mixed> $platform
     */
    protected function notify(
        Document $monitor,
        Document $alert,
        Document $project,
        Status $status,
        float $value,
        string $changedAt,
        Database $dbForPlatform,
        NotificationPublisher $publisherForNotifications,
        array $platform,
        ?Database $dbForProject = null,
    ): void {
        // The project's organization, not the one stored on the monitor: a transferred
        // project's alerts go to the members of the organization it moved to.
        $members = $this->members($dbForPlatform, (string) $project->getAttribute('teamInternalId', ''), $monitor->getAttribute('notify', [User::ROLE_OWNER]));
        if ($members === []) {
            return;
        }

        // Named after the evaluation the decision was made against, so a retried
        // evaluation of the same state reuses the key instead of sending twice.
        $deduplicationKey = 'monitor:' . $monitor->getId() . ':' . $status->value . ':' . ($monitor->getAttribute('evaluatedAt') ?? $monitor->getCreatedAt());
        $metric = (string) $monitor->getAttribute('metric');
        $parent = [
            'parentResourceType' => RESOURCE_TYPE_PROJECTS,
            'parentResourceId' => $project->getId(),
            'parentResourceInternalId' => (string) $project->getSequence(),
        ];

        $definition = Config::getParam('alerts', [])['metrics'][$metric] ?? [];
        $unit = (string) ($definition['unit'] ?? '');
        $resourceType = (string) $monitor->getAttribute('resourceType');
        $projectName = (string) $project->getAttribute('name');
        $team = $dbForPlatform->getDocument('teams', (string) $project->getAttribute('teamId', ''));
        $teamName = (string) $team->getAttribute('name', '');
        $region = (string) $project->getAttribute('region', '');
        $regionName = (string) (Config::getParam('regions', [])[$region]['name'] ?? '');
        $consoleUrl = (string) ($platform['consoleUrl'] ?? '');
        $endpoint = (System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'disabled' ? 'http' : 'https') . '://' . ($platform['apiHostname'] ?? System::getEnv('_APP_DOMAIN', 'localhost')) . '/v1';
        $unsubscribe = new Unsubscribe(System::getEnv('_APP_OPENSSL_KEY_V1'));
        $type = (string) $monitor->getAttribute('type');
        $typeConfig = Config::getParam('alerts', [])['types'][$type] ?? [];
        // Every email of a type is stopped at once only where the type allows it.
        $typeScope = ($typeConfig['optOut'] ?? false) === true ? Unsubscribe::type($type) : '';
        $notice = [
            'status' => $status,
            'rule' => (string) $monitor->getAttribute('name'),
            'metric' => (string) ($definition['label'] ?? $metric),
            'place' => $projectName,
            'value' => new Quantity($value, $unit),
            'comparator' => Comparator::from((string) $monitor->getAttribute('comparator')),
            'threshold' => new Quantity((float) $monitor->getAttribute('threshold'), $unit),
            'window' => ($definition['kind'] ?? 'gauge') !== 'gauge' ? (int) $monitor->getAttribute('window') : null,
            'changedAt' => new \DateTimeImmutable($changedAt),
            'details' => $resourceType === 'project' ? [] : [
                (string) (Config::getParam('alerts', [])['resources'][$resourceType]['label'] ?? \ucfirst($resourceType)) => $this->resourceName($monitor, $dbForProject) ?: (string) $monitor->getAttribute('resourceId'),
            ],
            'url' => $consoleUrl . $this->path($project) . '/alerts?alert=' . $alert->getId(),
            'location' => \implode(' · ', \array_filter([$projectName, $teamName, $regionName === 'default' ? '' : $regionName])),
            // When the alert opened, for how long it fired once it resolves.
            'since' => $alert->isEmpty() ? null : new \DateTimeImmutable($alert->getCreatedAt()),
            'cooldown' => (int) $monitor->getAttribute('cooldown', 0),
            // The monitor's drawer, which pauses it from its header.
            'pauseUrl' => $consoleUrl . $this->path($project) . '/monitors?monitor=' . $monitor->getId(),
        ];

        foreach ($members as [$user, $role]) {
            $resource = [
                'resourceType' => RESOURCE_TYPE_USERS,
                'resourceId' => $user->getId(),
                'resourceInternalId' => (string) $user->getSequence(),
                ...$parent,
            ];

            $recipients = [['address' => $user->getId(), 'channel' => NOTIFICATION_TYPE_CONSOLE, ...$resource]];
            $unsubscribed = $user->getAttribute('alertsUnsubscribed', []);
            if (
                (string) $user->getAttribute('email', '') !== ''
                && !\in_array($monitor->getId(), $unsubscribed, true)
                && ($typeScope === '' || !\in_array($typeScope, $unsubscribed, true))
            ) {
                $recipients[] = ['address' => (string) $user->getAttribute('email'), 'channel' => NOTIFICATION_TYPE_EMAIL, ...$resource];
            }

            $token = $unsubscribe->token($user->getId(), $monitor->getId());
            $email = new Email(
                ...$notice,
                reason: 'You\'re receiving this as ' . (\in_array(\strtolower($role[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ') . $role . ' of ' . $teamName . '.',
                unsubscribeUrl: $consoleUrl . '/auth/alerts/unsubscribe?token=' . \urlencode($token),
            );

            $this->publish($publisherForNotifications, new NotificationMessage(
                project: $project,
                recipients: $recipients,
                template: MAIL_TEMPLATE_ALERT,
                deduplicationKey: $deduplicationKey,
                subject: $email->subject(),
                bodyTemplate: APP_CE_CONFIG_DIR . '/locale/templates/email-base-styled.tpl',
                body: $email->render(APP_CE_CONFIG_DIR . '/locale/templates/email-alert.tpl'),
                preview: $email->summary(),
                variables: [
                    'heading' => $email->heading(),
                    'logoUrl' => $platform['logoUrl'] ?? APP_EMAIL_LOGO_URL,
                    'accentColor' => $platform['accentColor'] ?? APP_EMAIL_ACCENT_COLOR,
                    'twitter' => $platform['twitterUrl'] ?? APP_SOCIAL_TWITTER,
                    'discord' => $platform['discordUrl'] ?? APP_SOCIAL_DISCORD,
                    'github' => $platform['githubUrl'] ?? APP_SOCIAL_GITHUB_APPWRITE,
                    'terms' => $platform['termsUrl'] ?? APP_EMAIL_TERMS_URL,
                    'privacy' => $platform['privacyUrl'] ?? APP_EMAIL_PRIVACY_URL,
                    'platform' => $platform['platformName'] ?? APP_NAME,
                ],
                // One-click unsubscribe (RFC 8058) from this monitor's emails.
                headers: [
                    'List-Unsubscribe' => '<' . $endpoint . '/console/alerts/unsubscribe?project=console&token=' . \urlencode($token) . '>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ],
            ));
        }
    }

    /**
     * The name of the resource a scoped monitor watches, looked up in its type's
     * collection; empty when the resource is gone or has no name, and the
     * email shows its ID instead. Editions override this for resource types
     * kept outside the project's collections.
     */
    protected function resourceName(Document $monitor, ?Database $dbForProject): string
    {
        $collection = Config::getParam('alerts', [])['resources'][(string) $monitor->getAttribute('resourceType')]['collection'] ?? null;
        if ($collection === null || $dbForProject === null) {
            return '';
        }

        return (string) $dbForProject->getAuthorization()->skip(
            fn () => $dbForProject->getDocument($collection, (string) $monitor->getAttribute('resourceId'))->getAttribute('name', '')
        );
    }

    /**
     * The organization's confirmed members holding any of `$roles`, each with
     * the first of those roles they hold.
     *
     * @param array<string> $roles
     * @return list<array{0: Document, 1: string}>
     */
    protected function members(Database $dbForPlatform, string $teamInternalId, array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $memberRoles = [];
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
                $held = \array_values(\array_intersect($roles, $membership->getAttribute('roles', [])));
                if ($held !== []) {
                    $memberRoles[(string) $membership->getAttribute('userId')] ??= $held[0];
                }
            }

            $cursor = \end($memberships) ?: null;
        } while (\count($memberships) === APP_LIMIT_SUBQUERY);

        if ($memberRoles === []) {
            return [];
        }

        $users = $dbForPlatform->find('users', [
            Query::equal('$id', \array_map('strval', \array_keys($memberRoles))),
            Query::limit(\count($memberRoles)),
        ]);

        return \array_map(fn (Document $user) => [$user, $memberRoles[$user->getId()]], $users);
    }

    /**
     * A message the broker did not accept fails the evaluation, so the monitor's new
     * status is not stored and the next evaluation sees the transition again.
     */
    protected function publish(NotificationPublisher $publisherForNotifications, NotificationMessage $message): void
    {
        if ($publisherForNotifications->enqueue($message) === false) {
            throw new \RuntimeException('The notification for the alert was not accepted by the queue.');
        }
    }
}
