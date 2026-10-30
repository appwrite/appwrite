<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Alerts;

use Appwrite\Alert\Meter;
use Appwrite\Alert\Unsubscribe;
use Appwrite\Bus\Events\ExecutionCompleted;
use Appwrite\Bus\Events\RequestCompleted;
use Appwrite\Bus\Listeners\Usage as UsageListener;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Notification as NotificationMessage;
use Appwrite\Event\Message\ProjectContext;
use Appwrite\Event\Message\StatsResources as StatsResourcesMessage;
use Appwrite\Event\Message\Usage as UsageMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Publisher\Notification as NotificationPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Platform\Modules\Alerts\Workers\Alerts;
use Appwrite\Platform\Workers\StatsResources;
use Appwrite\Platform\Workers\StatsUsage;
use Appwrite\Usage\Concurrency;
use Appwrite\Usage\Connection;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Realtime\RealtimeBase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Config\Config;
use Utopia\Console\Command;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\System\System;
use Utopia\Usage\Adapter;
use Utopia\Usage\Metric;
use Utopia\Usage\Tenant;
use Utopia\Usage\Usage;
use WebSocket\TimeoutException;

use function Swoole\Coroutine\run;

/**
 * Drives monitors through the real scheduler, usage pipeline, evaluator and
 * notifications worker. The stack evaluates every `_APP_ALERTS_INTERVAL`
 * seconds, which the test environment keeps short.
 */
final class EvaluationCustomServerTest extends Scope
{
    use ProjectCustom;
    use RealtimeBase;
    use SideServer;

    private const int TIMEOUT_MS = 180_000;

    /** How many in-process notification checks ran, to space them past the cooldown. */
    private int $checks = 0;

    public function testMonitorFiresAndResolvesItsAlertThroughEveryChannel(): void
    {
        $name = 'Egress ' . \uniqid();
        $webhook = $this->createAlertWebhook();

        // Traffic for the project, so network.outbound has something to sum.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/monitors', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => $name,
            'rules' => [Query::greaterThan('network.outbound', 0)->toString()],
            'window' => 3600,
            'cooldown' => 300,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $firing = $this->waitForStatus($monitorId, 'firing');
        $this->assertGreaterThan(0, $firing['value']);
        $this->assertNotNull($firing['triggeredAt']);
        $this->assertNotNull($firing['evaluatedAt']);
        $this->assertNotSame('', $firing['alertId']);

        $alert = $this->getAlert($firing['alertId']);
        $this->assertSame('firing', $alert['status']);
        $this->assertSame($monitorId, $alert['monitorId']);
        $this->assertSame($name, $alert['name']);
        $this->assertSame('usage', $alert['type']);
        $this->assertSame('project', $alert['resourceType']);
        $this->assertSame('network.outbound', $alert['metric']);
        $this->assertSame('greaterThan', $alert['comparator']);
        $this->assertGreaterThan(0, $alert['value']);
        $this->assertGreaterThanOrEqual($alert['value'], $alert['peak']);
        $this->assertNull($alert['resolvedAt']);
        $this->assertSame([$firing['alertId']], \array_column($this->listAlerts([
            Query::equal('monitorId', [$monitorId])->toString(),
            Query::equal('status', ['firing'])->toString(),
        ])['alerts'], '$id'));

        // The project's webhook receives the event, signed like every project webhook, with the alert as its payload.
        $delivery = $this->waitForEvent($webhook['path'], $monitorId, 'firing');
        $this->assertSame(
            \base64_encode(\hash_hmac('sha1', $webhook['url'] . $delivery['body'], $webhook['secret'], true)),
            $delivery['headers']['X-Appwrite-Webhook-Signature'],
        );
        $this->assertContains('monitors.' . $monitorId . '.alerts.' . $firing['alertId'] . '.firing', \explode(',', $delivery['headers']['X-Appwrite-Webhook-Events']));
        $this->assertContains('monitors.*.alerts.*.firing', \explode(',', $delivery['headers']['X-Appwrite-Webhook-Events']));
        $this->assertSame($firing['alertId'], $delivery['data']['$id']);
        $this->assertSame($name, $delivery['data']['name']);
        $this->assertSame('usage', $delivery['data']['type']);
        $this->assertSame('network.outbound', $delivery['data']['metric']);
        $this->assertGreaterThan(0, $delivery['data']['value']);
        $this->assertNull($delivery['data']['resolvedAt']);
        $this->assertArrayNotHasKey('projectInternalId', $delivery['data']);

        // The project sends its own mail through custom SMTP; an alert is the
        // platform writing to the organization's owners, so it uses the platform mailer.
        $email = $this->getLastEmailByAddress($this->getRoot()['email'], function (array $email) use ($name) {
            $this->assertStringStartsWith('[Firing] ' . $name . ' · ', $email['subject']);
        });
        $this->assertSame(System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', 'team@localhost.test'), $email['from'][0]['address']);
        $this->assertStringContainsString($name, (string) $email['html']);
        // Values read in the metric's unit, and the button opens the alert itself.
        $this->assertStringContainsString('Outbound bandwidth is above 0 B', (string) $email['html']);
        $this->assertStringContainsString('/projects/' . $this->getProject()['$id'] . '/usage/alerts?alert=' . $firing['alertId'], (string) $email['html']);
        // "Pause this monitor" opens the monitor, whose drawer pauses it.
        $this->assertStringContainsString('/projects/' . $this->getProject()['$id'] . '/usage/monitors?monitor=' . $monitorId, (string) $email['html']);

        $bell = $this->client->call(Client::METHOD_GET, '/notifications', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'queries' => [Query::equal('projectId', [$this->getProject()['$id']])->toString()],
        ]);
        $this->assertSame(200, $bell['headers']['status-code']);
        $this->assertNotEmpty(\array_filter(\array_column($bell['body']['notifications'], 'title'), fn (string $title) => \str_starts_with($title, '[Firing] ' . $name . ' · ')));

        // Raising the threshold above any traffic the test made resolves the alert.
        $this->setThreshold($monitorId, 1e15);

        $resolved = $this->waitForStatus($monitorId, 'ok');
        $this->assertSame($firing['triggeredAt'], $resolved['triggeredAt']);
        $this->assertSame('', $resolved['alertId']);

        $alert = $this->getAlert($firing['alertId']);
        $this->assertSame('resolved', $alert['status']);
        $this->assertNotNull($alert['resolvedAt']);
        $this->assertSame(1, $alert['notifications']);
        $this->assertSame([], $this->listAlerts([
            Query::equal('monitorId', [$monitorId])->toString(),
            Query::equal('status', ['firing'])->toString(),
        ])['alerts']);

        $delivery = $this->waitForEvent($webhook['path'], $monitorId, 'resolved');
        $this->assertContains('monitors.' . $monitorId . '.alerts.' . $firing['alertId'] . '.resolved', \explode(',', $delivery['headers']['X-Appwrite-Webhook-Events']));
        $this->assertSame($firing['alertId'], $delivery['data']['$id']);
        $this->assertNotNull($delivery['data']['resolvedAt']);
        // The payload is the alert as it stands once resolved, its update time included.
        $this->assertSame($delivery['data']['resolvedAt'], $delivery['data']['$updatedAt']);
        $this->assertSame('resolved', $delivery['data']['status']);

        $this->assertEventually(function () use ($name) {
            $this->assertSame(['firing' => 1, 'resolved' => 1], $this->teamNotifications($name));
        }, 60_000, 1_000);
    }

    public function testMonitorNotifyingNobodyOnlyFiresEvents(): void
    {
        $name = 'Silent ' . \uniqid();
        $webhook = $this->createAlertWebhook();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/monitors', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => $name,
            'rules' => [Query::greaterThan('network.outbound', 0)->toString()],
            'cooldown' => 300,
            'notify' => [],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $this->assertSame([], $created['body']['notify']);
        $monitorId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        // The alert is recorded and its event delivered...
        $firing = $this->waitForStatus($monitorId, 'firing');
        $alert = $this->getAlert($firing['alertId']);
        $this->assertSame('firing', $alert['status']);
        $this->assertSame(0, $alert['notifications']);
        $this->waitForEvent($webhook['path'], $monitorId, 'firing');

        $this->setThreshold($monitorId, 1e15);
        $this->waitForStatus($monitorId, 'ok');
        $this->waitForEvent($webhook['path'], $monitorId, 'resolved');

        // ...while the owners hear nothing, by email or in the console.
        \sleep(10);
        $this->assertSame(['firing' => 0, 'resolved' => 0], $this->teamNotifications($name));
        $emails = \json_decode((string) \file_get_contents('http://maildev:1080/email'), true) ?: [];
        $this->assertSame([], \array_values(\array_filter(
            \array_column($emails, 'subject'),
            fn (string $subject) => \str_contains($subject, ' ' . $name . ' · '),
        )));

        // Listing the owners again notifies them of the next firing.
        $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $this->headers(), [
            'notify' => ['owner'],
            'rules' => [Query::greaterThan('network.outbound', 0)->toString()],
        ]);
        $this->assertSame(['owner'], $this->getMonitorBody($monitorId)['notify']);
        $this->waitForStatus($monitorId, 'firing');
        $this->assertEventually(function () use ($name) {
            $this->assertSame(1, $this->teamNotifications($name)['firing']);
        }, 60_000, 1_000);
    }

    public function testNotifyReachesTheListedRoles(): void
    {
        $console = $this->consoleHeaders();
        $teamId = $this->client->call(Client::METHOD_GET, '/projects/' . $this->getProject()['$id'], $console)['body']['teamId'];
        $developer = $this->addMember($teamId, ['developer']);
        $name = 'Roles ' . \uniqid();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/monitors', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => $name,
            'rules' => [Query::greaterThan('network.outbound', 0)->toString()],
            'cooldown' => 300,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        // Owners by default: the owner hears about the firing, the developer does not.
        $this->waitForStatus($monitorId, 'firing');
        $this->getLastEmailByAddress($this->getRoot()['email'], function (array $email) use ($name) {
            $this->assertStringStartsWith('[Firing] ' . $name . ' · ', $email['subject']);
        });
        \sleep(5);
        $this->assertSame([], $this->emailSubjects($developer, $name));

        // Listing developers too sends them the resolution.
        $updated = $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $this->headers(), [
            'notify' => ['owner', 'developer'],
        ]);
        $this->assertSame(['owner', 'developer'], $updated['body']['notify']);
        $this->setThreshold($monitorId, 1e15);
        $this->waitForStatus($monitorId, 'ok');

        $email = $this->getLastEmailByAddress($developer, function (array $email) use ($name) {
            $this->assertStringStartsWith('[Resolved] ' . $name . ' · ', $email['subject']);
        });
        $this->assertStringContainsString('as a developer of', (string) $email['html']);
    }

    public function testAlertEmailsCanBeUnsubscribed(): void
    {
        $name = 'Unsubscribe ' . \uniqid();
        $root = $this->getRoot();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/monitors', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => $name,
            'rules' => [Query::greaterThan('network.outbound', 0)->toString()],
            'cooldown' => 300,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        // Every alert email carries one-click unsubscribe headers and both links.
        $this->waitForStatus($monitorId, 'firing');
        $email = $this->getLastEmailByAddress($root['email'], function (array $email) use ($name) {
            $this->assertStringStartsWith('[Firing] ' . $name . ' · ', $email['subject']);
        });
        $this->assertSame('List-Unsubscribe=One-Click', $email['headers']['list-unsubscribe-post'] ?? null);
        $this->assertSame(1, \preg_match('#<(https?://[^>]+/v1/console/alerts/unsubscribe\?[^>]+)>#', (string) ($email['headers']['list-unsubscribe'] ?? ''), $header));
        $this->assertStringContainsString('Stop emails for this monitor', (string) $email['html']);
        $this->assertStringContainsString('Stop all usage alert emails', (string) $email['html']);
        $this->assertStringContainsString('/alerts/unsubscribe?token=', (string) $email['html']);
        \parse_str((string) \parse_url(\html_entity_decode($header[1]), PHP_URL_QUERY), $query);
        $token = (string) $query['token'];

        // Paused, so only the test evaluates it from here on.
        $this->assertSame(200, $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $this->headers(), ['enabled' => false])['headers']['status-code']);
        \sleep(2);

        // One click from the mail client, as RFC 8058 describes it.
        $unsubscribed = $this->client->call(Client::METHOD_POST, '/console/alerts/unsubscribe?project=console&token=' . \urlencode($token), [
            'content-type' => 'application/x-www-form-urlencoded',
        ], ['List-Unsubscribe' => 'One-Click']);
        $this->assertSame(204, $unsubscribed['headers']['status-code']);

        // Another monitor, not unsubscribed from.
        $other = $this->createPausedMonitor('Other ' . \uniqid());
        $this->assertSame([NOTIFICATION_TYPE_CONSOLE], $this->ownerChannels($monitorId, $root['$id']));
        $this->assertSame([NOTIFICATION_TYPE_CONSOLE, NOTIFICATION_TYPE_EMAIL], $this->ownerChannels($other, $root['$id']));

        // Every monitor's emails stop with the "all" link; console notifications stay.
        $all = (new Unsubscribe(System::getEnv('_APP_OPENSSL_KEY_V1')))->token($root['$id'], Unsubscribe::type('usage'));
        $this->assertSame(204, $this->unsubscribe(Client::METHOD_POST, $all)['headers']['status-code']);
        $this->assertSame([NOTIFICATION_TYPE_CONSOLE], $this->ownerChannels($other, $root['$id']));

        // Resuming undoes each, with the same token.
        $this->assertSame(204, $this->unsubscribe(Client::METHOD_DELETE, $all)['headers']['status-code']);
        $this->assertSame([NOTIFICATION_TYPE_CONSOLE, NOTIFICATION_TYPE_EMAIL], $this->ownerChannels($other, $root['$id']));
        $this->assertSame([NOTIFICATION_TYPE_CONSOLE], $this->ownerChannels($monitorId, $root['$id']));
        $this->assertSame(204, $this->unsubscribe(Client::METHOD_DELETE, $token)['headers']['status-code']);
        $this->assertSame([NOTIFICATION_TYPE_CONSOLE, NOTIFICATION_TYPE_EMAIL], $this->ownerChannels($monitorId, $root['$id']));

        /**
         * Test for FAILURE
         */
        $unsubscribe = new Unsubscribe(System::getEnv('_APP_OPENSSL_KEY_V1'));
        [$head, $claims, $signature] = \explode('.', $token);
        $forged = $head . '.' . \rtrim(\strtr(\base64_encode((string) \json_encode(['scope' => Unsubscribe::type('usage')] + (array) \json_decode((string) \base64_decode(\strtr($claims, '-_', '+/')), true))), '+/', '-_'), '=') . '.' . $signature;
        foreach ([
            'tampered' => $forged,
            'expired' => $unsubscribe->token($root['$id'], $monitorId, \time() - ALERT_UNSUBSCRIBE_TTL - 60),
            'another secret' => (new Unsubscribe('another-secret'))->token($root['$id'], $monitorId),
            'missing' => '',
        ] as $case => $invalid) {
            $refused = $this->unsubscribe(Client::METHOD_POST, $invalid);
            $this->assertSame(401, $refused['headers']['status-code'], $case);
            $this->assertSame('user_invalid_token', $refused['body']['type'], $case);
        }
        // None of them changed anything.
        $this->assertSame([NOTIFICATION_TYPE_CONSOLE, NOTIFICATION_TYPE_EMAIL], $this->ownerChannels($other, $root['$id']));
    }

    public function testProjectUsersCannotSeeMonitorsOrAlerts(): void
    {
        $projectId = $this->getProject()['$id'];
        $client = ['origin' => 'http://localhost', 'content-type' => 'application/json', 'x-appwrite-project' => $projectId];
        $email = 'end-user-' . \uniqid() . '@localhost.test';
        $password = 'password-' . \uniqid(); // pragma: allowlist secret
        $this->assertSame(201, $this->client->call(Client::METHOD_POST, '/account', $client, ['userId' => 'unique()', 'email' => $email, 'password' => $password])['headers']['status-code']);
        $session = $this->client->call(Client::METHOD_POST, '/account/sessions/email', $client, ['email' => $email, 'password' => $password]);
        $this->assertSame(201, $session['headers']['status-code']);
        $client['cookie'] = 'a_session_' . $projectId . '=' . $session['cookies']['a_session_' . $projectId];

        // A monitor that fires, so the realtime check below has an event to miss.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/monitors', $this->headers())['headers']['status-code']);
        }
        $user = $this->getWebsocket(['account', 'documents', 'files'], ['origin' => 'http://localhost', 'cookie' => $client['cookie']], $projectId, timeout: 5);
        $this->assertSame('connected', \json_decode($user->receive(), true)['type']);
        $console = $this->getWebsocket(['console'], ['origin' => 'http://localhost', 'cookie' => 'a_session_console=' . $this->getRoot()['session']], 'console', timeout: 5);
        $this->assertSame('connected', \json_decode($console->receive(), true)['type']);

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => 'Realtime ' . \uniqid(),
            'rules' => [Query::greaterThan('network.outbound', 0)->toString()],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];
        $firing = $this->waitForStatus($monitorId, 'firing');
        $event = 'monitors.' . $monitorId . '.alerts.' . $firing['alertId'] . '.firing';

        /**
         * Test for SUCCESS
         */
        // The console's members get the alert live...
        $message = $this->receiveUntilEvent($console, fn (array $message): bool => \in_array($event, $message['data']['events'] ?? [], true));
        $this->assertSame($firing['alertId'], $message['data']['payload']['$id']);
        $console->close();

        /**
         * Test for FAILURE
         */
        // ...the project's own users never do.
        while (true) {
            try {
                $frame = \json_decode($user->receive(), true);
            } catch (TimeoutException) {
                break;
            }
            $this->assertNotContains($event, $frame['data']['events'] ?? [], 'A project user received an alert over realtime.');
        }
        $user->close();

        // Nor can they read monitors or alerts over the API.
        foreach (['/monitors', '/monitors/' . $monitorId, '/alerts', '/alerts/' . $firing['alertId']] as $path) {
            $response = $this->client->call(Client::METHOD_GET, $path, $client);
            $this->assertSame(401, $response['headers']['status-code'], $path);
        }
        $this->assertSame(401, $this->client->call(Client::METHOD_DELETE, '/monitors/' . $monitorId, $client)['headers']['status-code']);
        $this->assertSame('firing', $this->getMonitorBody($monitorId)['status']);
    }

    public function testTransferredProjectAlertsItsNewOrganizationsOwners(): void
    {
        $console = [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ];
        $project = $this->getProject(true);
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $project['$id'],
            'x-appwrite-key' => $project['apiKey'],
        ];
        $name = 'Transferred ' . \uniqid();

        // Created before the transfer, and paused until it, so it cannot fire for the old organization.
        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $headers, [
            'monitorId' => 'unique()',
            'name' => $name,
            'rules' => [Query::greaterThan('network.outbound', 0)->toString()],
            'cooldown' => 300,
            'enabled' => false,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        // The destination has an owner the source organization does not.
        $destination = $this->createTeamFixture($console, [
            'teamId' => 'unique()',
            'name' => 'Destination ' . \uniqid(),
        ]);
        $this->assertSame(200, $destination['headers']['status-code']);
        $teamId = $destination['body']['$id'];

        $email = 'owner-' . \uniqid() . '@localhost.test';
        $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', $console, [
            'email' => $email,
            'roles' => ['owner'],
            'url' => 'http://localhost:5000/join-us#title',
        ]);
        $this->assertSame(201, $membership['headers']['status-code']);
        $invite = $this->extractQueryParamsFromEmailLink($this->getLastEmailByAddress($email, function (array $email) {
            $this->assertStringContainsString('/join-us', (string) ($email['html'] ?? ''));
        })['html']);
        $confirmed = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamId . '/memberships/' . $membership['body']['$id'] . '/status', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => $invite['userId'],
            'secret' => $invite['secret'],
        ]);
        $this->assertSame(200, $confirmed['headers']['status-code']);

        $transferred = $this->client->call(Client::METHOD_PATCH, '/projects/' . $project['$id'] . '/team', $console, [
            'teamId' => $teamId,
        ]);
        $this->assertSame(200, $transferred['headers']['status-code']);
        $this->assertSame($teamId, $transferred['body']['teamId']);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/monitors', $headers)['headers']['status-code']);
        }

        $resumed = $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $headers, ['enabled' => true]);
        $this->assertSame(200, $resumed['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $this->waitForStatus($monitorId, 'firing', $headers);

        $alert = $this->getLastEmailByAddress($email, function (array $email) use ($name) {
            $this->assertStringStartsWith('[Firing] ' . $name . ' · ', $email['subject']);
        });
        $this->assertStringContainsString('owner of ' . $destination['body']['name'], (string) $alert['html']);
    }

    public function testEachFiringOpensAnAlertThatOutlivesItsMonitor(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/monitors', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => 'History ' . \uniqid(),
            'rules' => [Query::greaterThan('network.outbound', 0)->toString()],
            'cooldown' => 300,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $first = $this->waitForStatus($monitorId, 'firing')['alertId'];
        $this->setThreshold($monitorId, 1e15);
        $this->waitForStatus($monitorId, 'ok');
        $this->setThreshold($monitorId, 0);
        $second = $this->waitForStatus($monitorId, 'firing')['alertId'];

        // A new firing is a new alert; the first one stays resolved.
        $this->assertNotSame($first, $second);
        $this->assertSame('resolved', $this->getAlert($first)['status']);

        $alerts = $this->listAlerts([
            Query::equal('monitorId', [$monitorId])->toString(),
            Query::orderAsc('$createdAt')->toString(),
        ]);
        $this->assertSame(2, $alerts['total']);
        $this->assertSame([$first, $second], \array_column($alerts['alerts'], '$id'));
        $this->assertSame(['resolved', 'firing'], \array_column($alerts['alerts'], 'status'));

        $resolved = $this->listAlerts([
            Query::equal('monitorId', [$monitorId])->toString(),
            Query::equal('status', ['resolved'])->toString(),
        ]);
        $this->assertSame([$first], \array_column($resolved['alerts'], '$id'));

        // Deleting the monitor resolves the alert it was firing and keeps its history.
        $deleted = $this->client->call(Client::METHOD_DELETE, '/monitors/' . $monitorId, $this->headers());
        $this->assertSame(204, $deleted['headers']['status-code']);

        $kept = $this->listAlerts([Query::equal('monitorId', [$monitorId])->toString()]);
        $this->assertSame(2, $kept['total']);
        $this->assertSame(['resolved', 'resolved'], \array_column($kept['alerts'], 'status'));
        $this->assertNotNull($this->getAlert($second)['resolvedAt']);

        // Maintenance removes resolved alerts once they are older than the retention.
        $retention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_ALERTS', '7776000');
        $this->withFactory(function (object $factory) use ($first, $retention): void {
            $factory->platform()->updateDocument('monitorAlerts', $first, new Document([
                'resolvedAt' => DateTime::addSeconds(new \DateTime(), -$retention - 3600),
            ]));
        });

        $stdout = '';
        $stderr = '';
        $code = Console::execute((new Command('docker'))->argument('exec')->argument('appwrite')->argument('maintenance')->argument('--type=trigger'), '', $stdout, $stderr);
        $this->assertSame(0, $code, "Maintenance command failed with code {$code}: {$stderr} ({$stdout})");

        $this->assertEventually(function () use ($first) {
            $gone = $this->client->call(Client::METHOD_GET, '/alerts/' . $first, $this->headers());
            $this->assertSame(404, $gone['headers']['status-code']);
        }, 30_000, 1_000);
        $this->assertSame($second, $this->getAlert($second)['$id']);

        /**
         * Test for FAILURE
         */
        $missing = $this->client->call(Client::METHOD_GET, '/alerts/missing', $this->headers());
        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('alert_not_found', $missing['body']['type']);

        $other = $this->getProject(true);
        $foreign = $this->client->call(Client::METHOD_GET, '/alerts/' . $first, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $other['$id'],
            'x-appwrite-key' => $other['apiKey'],
        ]);
        $this->assertSame(404, $foreign['headers']['status-code']);
    }

    public function testOverlappingEvaluationsOpenOneAlert(): void
    {
        $monitorId = $this->createPausedMonitor('Overlapping ' . \uniqid());

        /**
         * Test for SUCCESS
         */
        $errors = [];
        $this->inCoroutine(function () use ($monitorId, &$errors): void {
            // Neither evaluation opens its alert until both have read the monitor
            // and found none open, as when two workers evaluate it at once.
            $barrier = new Channel(1);
            $arrivals = 0;
            $group = new WaitGroup();
            for ($i = 0; $i < 2; $i++) {
                $group->add();
                Coroutine::create(function () use ($monitorId, $barrier, &$arrivals, &$errors, $group): void {
                    try {
                        $held = $this->heldPlatform(function () use ($barrier, &$arrivals): void {
                            if (++$arrivals === 1) {
                                $barrier->pop(10);
                            } else {
                                $barrier->push(true);
                            }
                        });

                        $this->evaluateMonitor($held, $monitorId, 20);
                    } catch (\Throwable $error) {
                        $errors[] = $error;
                    } finally {
                        $group->done();
                    }
                });
            }
            $group->wait(30);
        });
        $this->assertSame([], \array_map(fn (\Throwable $error) => $error->getMessage(), $errors));

        $monitor = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body'];
        $this->assertSame('firing', $monitor['status']);
        $alerts = $this->listAlerts([Query::equal('monitorId', [$monitorId])->toString()]);
        $this->assertSame(1, $alerts['total']);
        $this->assertSame([$monitor['alertId']], \array_column($alerts['alerts'], '$id'));
        $this->assertSame(['firing'], \array_column($alerts['alerts'], 'status'));

        // Recovering resolves the one alert, leaving nothing firing.
        $this->inCoroutine(fn () => $this->evaluateMonitor($this->platform(), $monitorId, 0));

        $alerts = $this->listAlerts([Query::equal('monitorId', [$monitorId])->toString()]);
        $this->assertSame(['resolved'], \array_column($alerts['alerts'], 'status'));
    }

    public function testStaleEvaluationLeavesAResolvedFiringAlone(): void
    {
        $monitorId = $this->createPausedMonitor('Stale ' . \uniqid());

        /**
         * Test for SUCCESS
         */
        $errors = [];
        $this->inCoroutine(function () use ($monitorId, &$errors): void {
            // One evaluation reads the monitor and, before it opens its alert, another
            // opens that alert and a third resolves it.
            $arrived = new Channel(1);
            $release = new Channel(1);
            $group = new WaitGroup();
            $group->add();
            Coroutine::create(function () use ($monitorId, $arrived, $release, &$errors, $group): void {
                try {
                    $held = $this->heldPlatform(function () use ($arrived, $release): void {
                        $arrived->push(true);
                        $release->pop(30);
                    });
                    $this->evaluateMonitor($held, $monitorId, 20);
                } catch (\Throwable $error) {
                    $errors[] = $error;
                } finally {
                    $group->done();
                }
            });

            $arrived->pop(30);
            $this->evaluateMonitor($this->platform(), $monitorId, 20);
            $this->evaluateMonitor($this->platform(), $monitorId, 0);
            $release->push(true);
            $group->wait(30);
        });
        $this->assertSame([], \array_map(fn (\Throwable $error) => $error->getMessage(), $errors));

        // The late evaluation neither reopens the firing nor points the monitor at its resolved alert.
        $monitor = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body'];
        $this->assertSame('ok', $monitor['status']);
        $this->assertSame('', $monitor['alertId']);
        $alerts = $this->listAlerts([Query::equal('monitorId', [$monitorId])->toString()]);
        $this->assertSame(['resolved'], \array_column($alerts['alerts'], 'status'));
    }

    public function testUnannouncedAlertResolvesWithItsMonitor(): void
    {
        $monitorId = $this->createPausedMonitor('Unannounced ' . \uniqid());

        /**
         * Test for SUCCESS
         */
        // The queue refuses the announcement: the alert is open, and the monitor keeps its old state.
        $this->assertSame('The notification for the alert was not accepted by the queue.', $this->evaluateRefused($monitorId));

        $monitor = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body'];
        $this->assertSame('ok', $monitor['status']);
        $this->assertSame('', $monitor['alertId']);
        $opened = $this->listAlerts([Query::equal('monitorId', [$monitorId])->toString()])['alerts'];
        $this->assertSame(['firing'], \array_column($opened, 'status'));
        $this->assertSame([0], \array_column($opened, 'notifications'));

        // Usage recovers before the evaluation is retried.
        $this->inCoroutine(fn () => $this->evaluateMonitor($this->platform(), $monitorId, 0));

        $alert = $this->getAlert($opened[0]['$id']);
        $this->assertSame('resolved', $alert['status']);
        $this->assertNotNull($alert['resolvedAt']);
        $this->assertSame('ok', $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body']['status']);

        // Deleting the monitor resolves an alert it opened but never announced as well.
        $this->evaluateRefused($monitorId);
        $this->assertCount(1, $this->listAlerts([
            Query::equal('monitorId', [$monitorId])->toString(),
            Query::equal('status', ['firing'])->toString(),
        ])['alerts']);

        $deleted = $this->client->call(Client::METHOD_DELETE, '/monitors/' . $monitorId, $this->headers());
        $this->assertSame(204, $deleted['headers']['status-code']);

        $alerts = $this->listAlerts([Query::equal('monitorId', [$monitorId])->toString()]);
        $this->assertSame(2, $alerts['total']);
        $this->assertSame(['resolved', 'resolved'], \array_column($alerts['alerts'], 'status'));
    }

    public function testCooldownHoldsBackTeamNotificationsButNotEvents(): void
    {
        $webhook = $this->createAlertWebhook();
        $name = 'Flapping ' . \uniqid();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, '/monitors', $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => $name,
            'rules' => [Query::greaterThan('network.outbound', 0)->toString()],
            'window' => 3600,
            'cooldown' => 3600,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        // The first firing and its resolution reach the team.
        $this->waitForStatus($monitorId, 'firing');
        $this->waitForEvent($webhook['path'], $monitorId, 'firing');
        $this->setThreshold($monitorId, 1e15);
        $this->waitForStatus($monitorId, 'ok');
        $this->waitForEvent($webhook['path'], $monitorId, 'resolved');

        // Firing again inside the cooldown is not sent to the team, and neither is its resolution...
        $this->setThreshold($monitorId, 0);
        $refiring = $this->waitForStatus($monitorId, 'firing');
        $this->setThreshold($monitorId, 1e15);
        $resolved = $this->waitForStatus($monitorId, 'ok');
        $this->assertSame($refiring['triggeredAt'], $resolved['triggeredAt']);

        // ...while every change is still an event.
        $this->assertEventually(function () use ($webhook, $monitorId) {
            $this->assertSame(['firing', 'resolved', 'firing', 'resolved'], $this->eventStatuses($webhook['path'], $monitorId));
        }, 60_000, 1_000);

        // Give the notifications worker time to deliver anything that was queued.
        \sleep(10);
        $this->assertSame(['firing' => 1, 'resolved' => 1], $this->teamNotifications($name));
    }

    public function testFiringThatOutlastsItsCooldownIsAnnounced(): void
    {
        // The test evaluates a paused monitor itself, at the times it chooses, so
        // the five-minute minimum cooldown passes without waiting for it.
        $monitorId = $this->createPausedMonitor('Sustained ' . \uniqid());
        $root = $this->getRoot()['$id'];
        $at = new \DateTimeImmutable();
        $sent = fn (array $messages): int => \count(\array_filter($messages, fn (NotificationMessage $message) => \in_array($root, \array_column($message->recipients, 'resourceId'), true)));

        /**
         * Test for SUCCESS
         */
        $this->inCoroutine(function () use ($monitorId, $at, $sent): void {
            $messages = [];
            $this->evaluateMonitor($this->platform(), $monitorId, 20, messages: $messages, now: $at);
            $this->assertSame(1, $sent($messages), 'The first firing is announced.');

            $messages = [];
            $this->evaluateMonitor($this->platform(), $monitorId, 0, messages: $messages, now: $at->modify('+60 seconds'));
            $this->assertSame(1, $sent($messages), 'Its resolution is announced.');

            // Firing again inside the cooldown is held back from the team...
            $messages = [];
            $this->evaluateMonitor($this->platform(), $monitorId, 20, messages: $messages, now: $at->modify('+120 seconds'));
            $this->assertSame(0, $sent($messages), 'A firing inside the cooldown was announced.');
            $this->evaluateMonitor($this->platform(), $monitorId, 20, messages: $messages, now: $at->modify('+240 seconds'));
            $this->assertSame(0, $sent($messages));

            // ...and announced by the first evaluation after the cooldown, while it still fires.
            $this->evaluateMonitor($this->platform(), $monitorId, 20, messages: $messages, now: $at->modify('+301 seconds'));
            $this->assertSame(1, $sent($messages), 'The firing that outlasted the cooldown was not announced.');
        });

        $monitor = $this->getMonitorBody($monitorId);
        $this->assertSame('firing', $monitor['status']);
        $this->assertSame(1, $this->getAlert($monitor['alertId'])['notifications']);
    }

    public function testPausedMonitorIsNotEvaluatedUntilResumed(): void
    {
        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => 'Paused ' . \uniqid(),
            'rules' => [Query::greaterThan('network.outbound', 1e15)->toString()],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $evaluated = $this->waitForEvaluation($monitorId, null);

        $paused = $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $this->headers(), ['enabled' => false]);
        $this->assertSame(200, $paused['headers']['status-code']);
        // Let an evaluation already queued before the pause finish first.
        \sleep(2);
        $before = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body']['evaluatedAt'];

        // Several evaluation intervals pass without the paused monitor being checked.
        \sleep(20);
        $monitor = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body'];
        $this->assertSame($before, $monitor['evaluatedAt']);

        $resumed = $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $this->headers(), ['enabled' => true]);
        $this->assertSame(200, $resumed['headers']['status-code']);
        $this->assertNotSame($evaluated, $this->waitForEvaluation($monitorId, $monitor['evaluatedAt']));
    }

    public function testDocumentsDBReadsReachTheirDatabaseMonitor(): void
    {
        // DocumentsDB reports reads as documentsdb.databases.operations.reads, so
        // a monitor on a DocumentsDB database must read that series, not the TablesDB one.
        $database = $this->client->call(Client::METHOD_POST, '/documentsdb', $this->headers(), [
            'databaseId' => 'unique()',
            'name' => 'Catalog',
        ]);
        $this->assertSame(201, $database['headers']['status-code']);
        $databaseId = $database['body']['$id'];

        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $databaseId . '/collections', $this->headers(), [
            'collectionId' => 'unique()',
            'name' => 'Products',
            'documentSecurity' => false,
            'permissions' => [],
        ]);
        $this->assertSame(201, $collection['headers']['status-code']);
        $documents = '/documentsdb/' . $databaseId . '/collections/' . $collection['body']['$id'] . '/documents';

        $document = $this->client->call(Client::METHOD_POST, $documents, $this->headers(), [
            'documentId' => 'unique()',
            'data' => ['sku' => 'A1'],
        ]);
        $this->assertSame(201, $document['headers']['status-code']);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->client->call(Client::METHOD_GET, $documents . '/' . $document['body']['$id'], $this->headers())['headers']['status-code']);
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => 'Catalog reads',
            'rules' => [
                Query::greaterThan('databases.operations.reads', 0)->toString(),
                Query::equal('resourceType', ['database'])->toString(),
                Query::equal('resourceId', [$databaseId])->toString(),
            ],
        ]);
        $this->assertSame(201, $created['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $firing = $this->waitForStatus($created['body']['$id'], 'firing');
        $this->assertGreaterThanOrEqual(3, $firing['value']);
    }

    /**
     * Every metric a usage monitor accepts, written by its real writer in the
     * shape that writer records it, then read back by the alerts worker for a
     * monitor on each scope the metric supports. Producers build their usage
     * through the same listener, context or publisher calls as production, and
     * the rows reach ClickHouse through the StatsUsage and StatsResources
     * workers and the realtime concurrency sampler. The usage goes to a tenant
     * of its own, so the expected values are exact.
     *
     * Each case that a plausible wrong dimension would break is also read with
     * that dimension, and must then miss: the test cannot pass on a meter that
     * filters where the writer does not record.
     */
    public function testEveryMetricReadsWhatItsWriterRecords(): void
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            $this->markTestSkipped('Usage stats are disabled on this stack');
        }

        $projectId = $this->getProject()['$id'];
        $resources = $this->createWatchedResources();
        $tenant = (string) \random_int(1_000_000_000, 9_999_999_999);
        $cases = [];
        $errors = [];

        $this->inCoroutine(function () use ($projectId, $resources, $tenant, &$cases, &$errors): void {
            try {
                global $register, $container;
                $authorization = new Authorization();
                $factory = $register->get('databaseFactory')($register->get('pools'), $register->get('cache'), $authorization);
                $authorization->skip(function () use ($factory, $container, $projectId, $resources, $tenant, &$cases): void {
                    $platform = $factory->platform();
                    $project = $platform->getDocument('projects', $projectId);
                    $dbForProject = $factory->project($project);
                    /** @var Connection $connection */
                    $connection = $container->get('usageConnection');
                    // The project as the workers see it, but writing to a tenant of its own.
                    $isolated = new Document(['$sequence' => $tenant] + $project->getArrayCopy());

                    $written = new \DateTimeImmutable();
                    $this->writeEvents($project, $isolated, $resources, $dbForProject, $connection);
                    $gauges = $this->writeGauges($project, $isolated, $resources, $factory, $dbForProject, $platform, $connection);
                    $cases = $this->metricCases($resources, $gauges);

                    $worker = new class () extends Alerts {
                        public function read(Document $monitor, Document $project, Database $dbForProject, Connection $connection, \DateTimeImmutable $now): ?float
                        {
                            return $this->measure($monitor, $project, $dbForProject, $connection, $now);
                        }
                    };
                    // Evaluated once the lag has passed over everything written, as the scheduler would.
                    $lag = $this->usageLag();
                    $later = (new \DateTimeImmutable())->add(new \DateInterval('PT' . ($lag + 1) . 'S'));

                    // Usage still inside the lag is not read yet: evaluated as it is written, there is nothing to compare.
                    $requests = $platform->getDocument('monitors', $this->createScopedMonitor(METRIC_NETWORK_REQUESTS, 'project', ''));
                    $cases['lag'] = ['early' => $worker->read($requests, $isolated, $dbForProject, $connection, $written), 'late' => $worker->read($requests, $isolated, $dbForProject, $connection, $later)];

                    foreach ($cases as $case => &$expectation) {
                        if ($case === 'lag') {
                            continue;
                        }
                        $monitor = $platform->getDocument('monitors', $this->createScopedMonitor($expectation['metric'], $expectation['resourceType'], $expectation['resourceId']));
                        $expectation['measured'] = $worker->read($monitor, $isolated, $dbForProject, $connection, $later);

                        if (isset($expectation['wrong'])) {
                            $registry = Config::getParam('alerts', []);
                            $scope = &$registry['metrics'][$expectation['metric']]['resources'][$expectation['resourceType']];
                            $scope = $expectation['wrong']($scope);
                            unset($scope);
                            $engine = $expectation['resourceType'] === 'database' ? (string) $dbForProject->getDocument('databases', $expectation['resourceId'])->getAttribute('type', '') : '';
                            $expectation['misread'] = (new Meter(new Tenant($connection->getUsage(), $tenant), $registry, $lag))->measure($monitor, $later, $engine);
                        }
                    }
                    unset($expectation);
                });
            } catch (\Throwable $error) {
                $errors[] = $error;
            }
        });

        if ($errors !== []) {
            throw $errors[0];
        }

        $alerts = Config::getParam('alerts', []);
        foreach ($alerts['metrics'] as $metric => $definition) {
            foreach (\array_keys($definition['resources']) as $resourceType) {
                $this->assertArrayHasKey("{$metric} on {$resourceType}", $cases, 'Every metric and scope a monitor accepts is proven against its writer');
            }
        }

        if ($this->usageLag() > 0) {
            $this->assertNull($cases['lag']['early'], 'Requests written inside the lag are not read yet');
        }
        $this->assertEqualsWithDelta(2.0, $cases['lag']['late'], 0.001, 'Requests are read once the lag has passed');
        unset($cases['lag']);

        /**
         * Test for SUCCESS
         */
        foreach ($cases as $case => $expectation) {
            // A reading of 0 still proves the scope: with no rows in its dimension the meter reads null.
            $this->assertNotNull($expectation['expected'], "{$case}: the writer recorded a reading. Storage rows: " . \json_encode($expectation['recorded']));
            $this->assertNotNull($expectation['measured'], "{$case}: the meter found the writer's rows");
            $this->assertEqualsWithDelta($expectation['expected'], $expectation['measured'], 0.001, $case);
            if (isset($expectation['wrong'])) {
                $misread = $expectation['misread'];
                $this->assertTrue($misread === null || \abs($misread - $expectation['expected']) > 0.001, "{$case}: reading it in another dimension must miss, read " . \var_export($misread, true));
            }
        }
    }

    /**
     * Without usage recorded for its window a monitor is not compared: an idle
     * project reads no data, not 0, so a `lessThan` monitor does not fire on it.
     * Once usage below the threshold is recorded, it does.
     */
    public function testMonitorWithoutUsageIsNotCompared(): void
    {
        if (System::getEnv('_APP_USAGE_STATS', 'enabled') === 'disabled') {
            $this->markTestSkipped('Usage stats are disabled on this stack');
        }

        // Nothing in this project sends messages, so the metric has no rows.
        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => 'Too few failures ' . \uniqid(),
            'rules' => [Query::lessThan(METRIC_MESSAGES_FAILED, 5)->toString()],
            'window' => 3600,
            'cooldown' => 0,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];

        /**
         * Test for SUCCESS
         */
        $first = $this->waitForEvaluation($monitorId, null);
        $this->waitForEvaluation($monitorId, $first);
        $monitor = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body'];
        $this->assertSame('ok', $monitor['status']);
        $this->assertNull($monitor['value'], 'Evaluated without data, the monitor has no reading');
        $this->assertSame(0, $this->listAlerts([Query::equal('monitorId', [$monitorId])->toString()])['total']);

        // One failure, recorded where the messaging worker records it, before the lag.
        $at = (new \DateTimeImmutable())->sub(new \DateInterval('PT' . ($this->usageLag() + 30) . 'S'));
        $this->inCoroutine(function () use ($at): void {
            global $container;
            $project = $this->platform()->getDocument('projects', $this->getProject()['$id']);
            $usage = (new UsageContext())
                ->setResource(METRIC_MESSAGES_RESOURCE_TYPE)
                ->setResourceId(Id::unique())
                ->setResourceInternalId('1')
                ->addMetric(METRIC_MESSAGES_FAILED, 1);
            $stats = new StatsUsage();
            $stats->action(
                new Message(['pid' => \uniqid(), 'queue' => 'stats-usage', 'timestamp' => \time(), 'payload' => ['timestamp' => \gmdate('Y-m-d H:i:s', $at->getTimestamp())] + (new UsageMessage($project, $usage->getMetrics()))->toArray()]),
                ProjectContext::fromDocument($project),
                $container->get('usageConnection'),
            );
            // StatsUsage writes its batch on a timer.
            Coroutine::sleep(7);
        });

        $firing = $this->waitForStatus($monitorId, 'firing');
        $this->assertEqualsWithDelta(1.0, $firing['value'], 0.001);
    }

    /**
     * Deleting a bucket deletes the monitors scoped to it, resolving the alert
     * one has open and keeping it in the history.
     */
    public function testDeletingAResourceDeletesItsMonitors(): void
    {
        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $this->headers(), [
            'bucketId' => Id::unique(),
            'name' => 'Short-lived',
            'fileSecurity' => false,
            'permissions' => [],
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);
        $bucketId = $bucket['body']['$id'];

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => 'Bucket storage ' . \uniqid(),
            'rules' => [
                Query::greaterThan(METRIC_STORAGE, 10)->toString(),
                Query::equal('resourceType', ['bucket'])->toString(),
                Query::equal('resourceId', [$bucketId])->toString(),
            ],
            'cooldown' => 0,
            'enabled' => false,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);
        $monitorId = $created['body']['$id'];
        $this->inCoroutine(fn () => $this->evaluateMonitor($this->platform(), $monitorId, 20));
        $alertId = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body']['alertId'];
        $this->assertNotEmpty($alertId);
        $this->assertSame('firing', $this->getAlert($alertId)['status']);

        $deleted = $this->client->call(Client::METHOD_DELETE, '/storage/buckets/' . $bucketId, $this->headers());
        $this->assertSame(204, $deleted['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $this->assertEventually(function () use ($monitorId) {
            $this->assertSame(404, $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['headers']['status-code']);
        }, self::TIMEOUT_MS, 1_000);
        $alert = $this->getAlert($alertId);
        $this->assertSame('resolved', $alert['status']);
        $this->assertNotNull($alert['resolvedAt']);
    }

    /**
     * The resources monitors are scoped to: a bucket holding a file, a TablesDB
     * database with a row and a DocumentsDB database with a collection, a
     * function, a site, and users, one of them active this month.
     *
     * @return array<string, string> resource IDs by kind
     */
    private function createWatchedResources(): array
    {
        $headers = $this->headers();

        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', $headers, [
            'bucketId' => Id::unique(),
            'name' => 'Watched',
            'fileSecurity' => false,
            'permissions' => [],
        ]);
        $this->assertSame(201, $bucket['headers']['status-code']);
        $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', ['content-type' => 'multipart/form-data'] + $headers, [
            'fileId' => Id::unique(),
            'file' => new \CURLFile((string) \realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
        ]);
        $this->assertSame(201, $file['headers']['status-code']);

        $tables = $this->client->call(Client::METHOD_POST, '/tablesdb', $headers, ['databaseId' => Id::unique(), 'name' => 'Ledger']);
        $this->assertSame(201, $tables['headers']['status-code']);
        $table = $this->client->call(Client::METHOD_POST, '/tablesdb/' . $tables['body']['$id'] . '/tables', $headers, [
            'tableId' => Id::unique(),
            'name' => 'Entries',
            'rowSecurity' => false,
            'permissions' => [],
        ]);
        $this->assertSame(201, $table['headers']['status-code']);
        // A row, so the table has pages for the sweep to measure.
        $entries = '/tablesdb/' . $tables['body']['$id'] . '/tables/' . $table['body']['$id'];
        $column = $this->client->call(Client::METHOD_POST, $entries . '/columns/integer', $headers, ['key' => 'amount', 'required' => true]);
        $this->assertSame(202, $column['headers']['status-code']);
        $this->assertEventually(function () use ($entries, $headers) {
            $column = $this->client->call(Client::METHOD_GET, $entries . '/columns/amount', $headers);
            $this->assertSame('available', $column['body']['status'] ?? null);
        });
        $row = $this->client->call(Client::METHOD_POST, $entries . '/rows', $headers, ['rowId' => Id::unique(), 'data' => ['amount' => 1]]);
        $this->assertSame(201, $row['headers']['status-code']);

        $documents = $this->client->call(Client::METHOD_POST, '/documentsdb', $headers, ['databaseId' => Id::unique(), 'name' => 'Catalog']);
        $this->assertSame(201, $documents['headers']['status-code']);
        $collection = $this->client->call(Client::METHOD_POST, '/documentsdb/' . $documents['body']['$id'] . '/collections', $headers, [
            'collectionId' => Id::unique(),
            'name' => 'Products',
            'documentSecurity' => false,
            'permissions' => [],
        ]);
        $this->assertSame(201, $collection['headers']['status-code']);

        $function = $this->client->call(Client::METHOD_POST, '/functions', $headers, [
            'functionId' => Id::unique(),
            'name' => 'Watched',
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
        ]);
        $this->assertSame(201, $function['headers']['status-code']);

        $site = $this->client->call(Client::METHOD_POST, '/sites', $headers, [
            'siteId' => Id::unique(),
            'name' => 'Watched',
            'framework' => 'other',
            'buildRuntime' => 'node-22',
        ]);
        $this->assertSame(201, $site['headers']['status-code']);

        $user = $this->client->call(Client::METHOD_POST, '/users', $headers, [
            'userId' => Id::unique(),
            'email' => \uniqid() . '@watched.test',
            'password' => 'password-watched',
        ]);
        $this->assertSame(201, $user['headers']['status-code']);

        return [
            'bucket' => $bucket['body']['$id'],
            'tablesdb' => $tables['body']['$id'],
            'documentsdb' => $documents['body']['$id'],
            'function' => $function['body']['$id'],
            'site' => $site['body']['$id'],
            'user' => $user['body']['$id'],
        ];
    }

    /**
     * Publishes the usage each event metric's producer publishes, through the
     * StatsUsage worker, and samples realtime concurrency from its deltas.
     *
     * @param array<string, string> $resources
     */
    private function writeEvents(Document $project, Document $isolated, array $resources, Database $dbForProject, Connection $connection): void
    {
        $messages = [];
        $publisher = $this->createStub(UsagePublisher::class);
        $publisher->method('enqueue')->willReturnCallback(function (UsageMessage $message) use (&$messages): bool {
            $messages[] = $message->toArray();

            return true;
        });
        $listener = new UsageListener();
        $function = $dbForProject->getDocument('functions', $resources['function']);
        $site = $dbForProject->getDocument('sites', $resources['site'])->setAttribute('adapter', 'ssr');
        $request = function (int $inbound, int $outbound): array {
            $request = $this->createStub(Request::class);
            $request->method('getSize')->willReturn($inbound);
            $response = $this->createStub(Response::class);
            $response->method('getSize')->willReturn($outbound);

            return [$request, $response];
        };
        $publish = function (UsageContext $usage) use ($project, &$messages): void {
            $messages[] = (new UsageMessage($project, $usage->getMetrics()))->toArray();
        };

        // An API request: the listener meters it, the api shutdown fills the project in.
        [$apiRequest, $apiResponse] = $request(100, 1000);
        $api = new UsageContext();
        $listener->handle(new RequestCompleted($project->getArrayCopy(), $apiRequest, $apiResponse), $publisher, $api);
        $api->fillMissingResource('project', $project->getId(), (string) $project->getSequence());
        $publish($api);

        // A request the router served from a site.
        [$siteRequest, $siteResponse] = $request(200, 2000);
        $siteTraffic = new UsageContext();
        $listener->handle(new RequestCompleted($project->getArrayCopy(), $siteRequest, $siteResponse, ['resourceType' => 'sites', 'resourceInternalId' => (string) $site->getSequence()]), $publisher, $siteTraffic);
        $publish($siteTraffic);

        // Two function executions and one SSR site execution.
        foreach ([[$function, 'functions', 1.5], [$function, 'functions', 1.5], [$site, 'sites', 2.0]] as [$resource, $type, $duration]) {
            $listener->handle(new ExecutionCompleted(
                execution: ['resourceType' => $type, 'resourceInternalId' => (string) $resource->getSequence(), 'duration' => $duration],
                project: $project->getArrayCopy(),
                spec: ['memory' => 512, 'cpus' => 1],
                resource: $resource->getArrayCopy(),
            ), $publisher, new UsageContext());
        }

        // Document and row routes meter operations on their database, under its engine's name.
        foreach ([['tablesdb', '', 5, 2], ['documentsdb', DATABASE_TYPE_DOCUMENTSDB . '.', 3, 1]] as [$kind, $prefix, $reads, $writes]) {
            $database = $dbForProject->getDocument('databases', $resources[$kind]);
            $publish((new UsageContext())
                ->setResource('database')
                ->setResourceId($database->getId())
                ->setResourceInternalId((string) $database->getSequence())
                ->addMetric($prefix . METRIC_DATABASES_OPERATIONS_READS, $reads)
                ->addMetric($prefix . METRIC_DATABASES_OPERATIONS_WRITES, $writes));
        }

        // The webhooks worker, per delivery.
        foreach ([METRIC_WEBHOOKS_SENT, METRIC_WEBHOOKS_SENT, METRIC_WEBHOOKS_SENT, METRIC_WEBHOOKS_FAILED] as $outcome) {
            $publish((new UsageContext())->setResource('webhook')->setResourceInternalId('1')->addMetric($outcome, 1));
        }

        // The messaging worker, per batch.
        $publish((new UsageContext())
            ->setResource(METRIC_MESSAGES_RESOURCE_TYPE)
            ->setResourceId(Id::unique())
            ->setResourceInternalId('1')
            ->addMetric(METRIC_MESSAGES_SENT, 5)
            ->addMetric(METRIC_MESSAGES_FAILED, 2)
            ->addMetric(\str_replace('{type}', MESSAGE_TYPE_SMS, METRIC_MESSAGES_TYPE_SENT), 5));

        // Request-scoped metrics: a phone OTP and two screenshots.
        foreach ([[METRIC_AUTH_METHOD_PHONE, 1], [METRIC_AVATARS_SCREENSHOTS_GENERATED, 2]] as [$metric, $value]) {
            $publish((new UsageContext())->addMetric($metric, $value)->fillMissingResource('project', $project->getId(), (string) $project->getSequence()));
        }

        // Realtime opens and closes, as triggerStats publishes them, inside the
        // last bucket the concurrency sampler has closed.
        $closed = \intdiv(\time() - REALTIME_CONCURRENCY_LAG_SECONDS, 300) * 300;
        $deltas = [];
        foreach ([1, 1, 1, -1] as $delta) {
            $deltas[] = ['timestamp' => \gmdate('Y-m-d H:i:s', $closed - 150)] + (new UsageMessage($project, (new UsageContext())->addMetric(METRIC_REALTIME_CONNECTIONS, $delta)->getMetrics()))->toArray();
        }

        $stats = new StatsUsage();
        foreach ([...$messages, ...$deltas] as $payload) {
            $stats->action(new Message(['pid' => \uniqid(), 'queue' => 'stats-usage', 'timestamp' => \time(), 'payload' => $payload]), new ProjectContext(id: $project->getId(), sequence: $isolated->getSequence()), $connection);
        }
        // StatsUsage writes its batch on a timer.
        Coroutine::sleep(7);

        // The stats-resources task samples every tenant; this one sees only the test's.
        $tenant = $isolated->getSequence();
        $scoped = new class ($connection->getUsage()->getAdapter(), $tenant) extends Usage {
            public function __construct(Adapter $adapter, private readonly string $tenant)
            {
                parent::__construct($adapter);
            }

            public function findAcrossTenants(array $queries = [], ?string $type = null): array
            {
                return \array_map(fn (Metric $row): Metric => $row->setAttribute('tenant', $this->tenant), $this->find($this->tenant, $queries, $type));
            }
        };
        $this->assertGreaterThan(0, (new Concurrency())->sample($scoped), 'The concurrency sampler writes a level for the test tenant');
    }

    /**
     * Runs the resource sweep over the project and writes its gauges to the
     * isolated tenant, as the stats-resources worker does.
     *
     * @param array<string, string> $resources
     * @return list<array<string, mixed>> the gauges the sweep recorded
     */
    private function writeGauges(Document $project, Document $isolated, array $resources, object $factory, Database $dbForProject, Database $platform, Connection $connection): array
    {
        // An active user, so monthly active users has someone to count.
        foreach ($dbForProject->find('users', [Query::limit(1)]) as $user) {
            $dbForProject->updateDocument('users', $user->getId(), new Document(['accessedAt' => DateTime::now()]));
        }

        // A deployment each for the function and the site, so their storage has bytes to report.
        foreach ([['functions', $resources['function']], ['sites', $resources['site']]] as [$collection, $resourceId]) {
            $resource = $dbForProject->getDocument($collection, $resourceId);
            $dbForProject->createDocument('deployments', new Document([
                '$id' => Id::unique(),
                'resourceType' => $collection,
                'resourceId' => $resource->getId(),
                'resourceInternalId' => (string) $resource->getSequence(),
                'type' => 'manual',
                'sourceSize' => 4096,
                'buildSize' => 1024,
            ]));
        }

        $getDatabasesDB = fn (Document $database): Database => $factory->tenant(
            $database->getAttribute('database', '') === '' ? new Document(['database' => $project->getAttribute('database', '')] + $database->getArrayCopy()) : $database,
            $project,
        );

        $sweep = new class () extends StatsResources {
            /**
             * @return array<int, array<string, mixed>>
             */
            public function sweep(Document $project, Database $dbForProject, Database $platform, callable $getDatabasesDB): array
            {
                return $this->count($project, $dbForProject, $platform, $getDatabasesDB);
            }
        };
        $gauges = $sweep->sweep($project, $dbForProject, $platform, $getDatabasesDB);

        $sweep->action(
            new Message(['pid' => \uniqid(), 'queue' => 'stats-resources', 'timestamp' => \time(), 'payload' => (new StatsResourcesMessage($isolated, $gauges))->toArray()]),
            $isolated,
            $dbForProject,
            $platform,
            $getDatabasesDB,
            $connection,
        );

        return $gauges;
    }

    /**
     * What each metric must read on each scope: the totals of the usage
     * {@see writeEvents} published, and for gauges the reading the sweep
     * recorded for that scope. `wrong` rewrites the scope into a dimension the
     * writer does not record.
     *
     * @param array<string, string> $resources
     * @param list<array<string, mixed>> $gauges
     * @return array<string, array{metric: string, resourceType: string, resourceId: string, expected: ?float, recorded: list<array<string, mixed>>, wrong?: \Closure(array<string, mixed>): array<string, mixed>}>
     */
    private function metricCases(array $resources, array $gauges): array
    {
        // The sweep's reading for a scope, or null when it recorded none.
        $gauge = function (string $metric, ?string $resourceType = null, ?string $resourceId = null) use ($gauges): ?float {
            foreach ($gauges as $row) {
                if ($row['metric'] === $metric && ($row['resourceType'] ?? null) === $resourceType && ($resourceId === null || ($row['resourceId'] ?? null) === $resourceId)) {
                    return (float) $row['value'];
                }
            }

            return null;
        };
        $sum = static fn (?float ...$readings): ?float => \in_array(null, $readings, true) ? null : \array_sum($readings);
        $project = $this->getProject()['$id'];
        $sms = \str_replace('{type}', MESSAGE_TYPE_SMS, METRIC_MESSAGES_TYPE_SENT);
        // The sweep records gauges by public ID only; events carry both IDs, so they go wrong by a resource type the writer never records.
        $byInternalId = static fn (array $scope): array => ['id' => 'resourceInternalId'] + $scope;
        $plural = static fn (array $scope): array => ['resourceType' => $scope['resourceType'] . 's'] + $scope;
        $taggedProject = static fn (array $scope): array => ['resourceType' => 'project'] + $scope;
        $oneEngine = static fn (array $scope): array => ['engines' => false] + $scope;
        $databaseStorage = $sum($gauge(METRIC_DATABASES_STORAGE, 'project'), $gauge(METRIC_DATABASES_STORAGE_DOCUMENTSDB, 'project'), $gauge(METRIC_DATABASES_STORAGE_VECTORSDB, 'project'));

        $cases = [
            [METRIC_NETWORK_REQUESTS, 'project', '', 2, $taggedProject],
            [METRIC_NETWORK_INBOUND, 'project', '', 300, $taggedProject],
            [METRIC_NETWORK_OUTBOUND, 'project', '', 3000, $taggedProject],
            [METRIC_EXECUTIONS, 'project', '', 3],
            [METRIC_EXECUTIONS, 'function', $resources['function'], 2, $plural],
            [METRIC_EXECUTIONS, 'site', $resources['site'], 1, $plural],
            [METRIC_EXECUTIONS_MB_SECONDS, 'project', '', 768 * 2 + 1024],
            [METRIC_EXECUTIONS_MB_SECONDS, 'function', $resources['function'], 768 * 2],
            [METRIC_EXECUTIONS_MB_SECONDS, 'site', $resources['site'], 1024],
            [METRIC_REALTIME_CONNECTIONS, 'project', '', 2, $taggedProject],
            [METRIC_WEBHOOKS_SENT, 'project', '', 3],
            [METRIC_WEBHOOKS_FAILED, 'project', '', 1],
            [METRIC_MAU, 'project', '', $gauge(METRIC_MAU), $taggedProject],
            [METRIC_AUTH_METHOD_PHONE, 'project', '', 1],
            // The isolated tenant has no earlier users reading, so every user counts as signed up in the window.
            [METRIC_USERS, 'project', '', $gauge(METRIC_USERS), $taggedProject],
            [METRIC_DATABASES_OPERATIONS_READS, 'project', '', 8, $oneEngine],
            [METRIC_DATABASES_OPERATIONS_READS, 'database', $resources['tablesdb'], 5, $plural],
            [METRIC_DATABASES_OPERATIONS_READS, 'database', $resources['documentsdb'], 3, $oneEngine],
            [METRIC_DATABASES_OPERATIONS_WRITES, 'project', '', 3, $oneEngine],
            [METRIC_DATABASES_OPERATIONS_WRITES, 'database', $resources['tablesdb'], 2, $plural],
            [METRIC_DATABASES_STORAGE, 'project', '', $databaseStorage],
            [METRIC_DATABASES_STORAGE, 'database', $resources['tablesdb'], $gauge(METRIC_DATABASES_STORAGE, 'database', $resources['tablesdb']), $byInternalId],
            [METRIC_STORAGE, 'project', '', $sum($gauge(METRIC_FILES_STORAGE, 'project', $project), $databaseStorage, $gauge(METRIC_DEPLOYMENTS_STORAGE, 'deployment', $project), $gauge(METRIC_BUILDS_STORAGE, 'build', $project))],
            [METRIC_STORAGE, 'bucket', $resources['bucket'], $gauge(METRIC_STORAGE, 'bucket', $resources['bucket']), $byInternalId],
            [METRIC_STORAGE, 'database', $resources['tablesdb'], $gauge(METRIC_STORAGE, 'database', $resources['tablesdb'])],
            [METRIC_STORAGE, 'function', $resources['function'], $gauge(METRIC_STORAGE, 'function', $resources['function'])],
            [METRIC_STORAGE, 'site', $resources['site'], $gauge(METRIC_STORAGE, 'site', $resources['site'])],
            [METRIC_FILES_STORAGE, 'project', '', $gauge(METRIC_FILES_STORAGE, 'project', $project)],
            [METRIC_MESSAGES_SENT, 'project', '', 5],
            [$sms, 'project', '', 5],
            [METRIC_MESSAGES_FAILED, 'project', '', 2],
            [METRIC_AVATARS_SCREENSHOTS_GENERATED, 'project', '', 2],
        ];

        $expectations = [];
        foreach ($cases as $case) {
            [$metric, $resourceType, $resourceId, $expected] = $case;
            $suffix = $resourceType === 'database' && $resourceId === $resources['documentsdb'] ? ' (documentsdb)' : '';
            $expectations["{$metric} on {$resourceType}{$suffix}"] = [
                'metric' => $metric,
                'resourceType' => $resourceType,
                'resourceId' => $resourceId,
                'expected' => $expected === null ? null : (float) $expected,
                // The sweep's database rows, to show why one is missing.
                'recorded' => \array_values(\array_filter($gauges, static fn (array $row): bool => \str_contains((string) $row['metric'], 'storage'))),
            ] + (isset($case[4]) ? ['wrong' => $case[4]] : []);
        }

        return $expectations;
    }

    /**
     * The lag the alerts worker reads usage behind, as it reads it.
     */
    private function usageLag(): int
    {
        $lag = System::getEnv('_APP_ALERTS_USAGE_LAG', '');

        return \is_numeric($lag) ? (int) $lag : ALERTS_USAGE_LAG_SECONDS;
    }

    /**
     * A paused monitor on one metric and scope, so the scheduler leaves it to the test.
     */
    private function createScopedMonitor(string $metric, string $resourceType, string $resourceId): string
    {
        $rules = [Query::greaterThan($metric, 0)->toString()];
        if ($resourceType !== 'project') {
            $rules[] = Query::equal('resourceType', [$resourceType])->toString();
            $rules[] = Query::equal('resourceId', [$resourceId])->toString();
        }

        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => "{$metric} on {$resourceType}",
            'rules' => $rules,
            'window' => 3600,
            'enabled' => false,
        ]);
        $this->assertSame(201, $created['headers']['status-code'], "{$metric} on {$resourceType}: " . \json_encode($created['body']));

        return $created['body']['$id'];
    }

    /**
     * @param callable(object): void $callback receives the registered `databaseFactory`, whose class an edition may replace
     */
    private function withFactory(callable $callback): void
    {
        $this->inCoroutine(function () use ($callback): void {
            global $register;
            $authorization = new Authorization();
            $factory = $register->get('databaseFactory')($register->get('pools'), $register->get('cache'), $authorization);

            $authorization->skip(fn () => $callback($factory));
        });
    }

    private function inCoroutine(callable $callback): void
    {
        if (Coroutine::getCid() >= 0) {
            $callback();
        } else {
            run($callback);
        }
    }

    /**
     * The platform database as the alerts worker sees it, with authorization off.
     */
    private function platform(): Database
    {
        global $register;

        return $register->get('databaseFactory')($register->get('pools'), $register->get('cache'), new Authorization(false))->platform();
    }

    /**
     * The platform database, calling `$beforeAlertCreate` each time an alert is about to be created.
     */
    private function heldPlatform(callable $beforeAlertCreate): Database
    {
        $platform = $this->platform();
        $held = self::getStubBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDocument', 'findOne', 'find', 'createDocument', 'updateDocument'])
            ->getStub();
        $held->method('getDocument')->willReturnCallback(fn (mixed ...$arguments): Document => $platform->getDocument(...$arguments));
        $held->method('findOne')->willReturnCallback(fn (mixed ...$arguments): Document => $platform->findOne(...$arguments));
        $held->method('find')->willReturnCallback(fn (mixed ...$arguments): array => $platform->find(...$arguments));
        $held->method('updateDocument')->willReturnCallback(fn (mixed ...$arguments): Document => $platform->updateDocument(...$arguments));
        $held->method('createDocument')->willReturnCallback(function (string $collection, Document $document) use ($platform, $beforeAlertCreate): Document {
            if ($collection === 'monitorAlerts') {
                $beforeAlertCreate();
            }

            return $platform->createDocument($collection, $document);
        });

        return $held;
    }

    /**
     * A paused monitor the scheduler leaves alone, so only the test evaluates it.
     */
    private function createPausedMonitor(string $name): string
    {
        $created = $this->client->call(Client::METHOD_POST, '/monitors/usage', $this->headers(), [
            'monitorId' => 'unique()',
            'name' => $name,
            'rules' => [Query::greaterThan('network.outbound', 10)->toString()],
            'cooldown' => 300,
            'enabled' => false,
        ]);
        $this->assertSame(201, $created['headers']['status-code']);

        return $created['body']['$id'];
    }

    /**
     * Runs the alerts worker's evaluation of the monitor against a measured value,
     * with a queue that accepts or refuses its announcements.
     */
    /**
     * @param list<NotificationMessage> $messages receives what the evaluation published
     */
    private function evaluateMonitor(Database $dbForPlatform, string $monitorId, float $value, bool $accepted = true, array &$messages = [], ?\DateTimeImmutable $now = null): void
    {
        $publisher = $this->createStub(NotificationPublisher::class);
        $publisher->method('enqueue')->willReturnCallback(function (NotificationMessage $message) use ($accepted, &$messages): bool {
            $messages[] = $message;

            return $accepted;
        });

        $evaluator = new class () extends Alerts {
            public function evaluateNow(Document $monitor, Document $project, float $value, Database $dbForPlatform, NotificationPublisher $publisher, \DateTimeImmutable $now): void
            {
                $events = new MockPublisher();
                $realtime = new class () extends Realtime {
                    public function trigger(): string|bool
                    {
                        return true;
                    }
                };

                $this->evaluate($monitor, $project, $value, $now, $dbForPlatform, $publisher, new Event($events), new Webhook($events), new FunctionPublisher($events, new Queue(Event::FUNCTIONS_QUEUE_NAME)), $realtime, []);
            }
        };

        $evaluator->evaluateNow(
            $dbForPlatform->getDocument('monitors', $monitorId),
            $dbForPlatform->getDocument('projects', $this->getProject()['$id']),
            $value,
            $dbForPlatform,
            $publisher,
            $now ?? new \DateTimeImmutable(),
        );
    }

    /**
     * Evaluates the monitor as firing while the queue refuses the announcement.
     *
     * @return string the error the evaluation failed with
     */
    private function evaluateRefused(string $monitorId): string
    {
        $message = '';
        $this->inCoroutine(function () use ($monitorId, &$message): void {
            try {
                $this->evaluateMonitor($this->platform(), $monitorId, 20, false);
            } catch (\RuntimeException $error) {
                $message = $error->getMessage();
            }
        });

        return $message;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    /**
     * @return array<string, mixed>
     */
    private function getAlert(string $alertId): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/alerts/' . $alertId, $this->headers());
        $this->assertSame(200, $response['headers']['status-code']);

        return $response['body'];
    }

    /**
     * @param array<string> $queries
     * @return array<string, mixed>
     */
    private function listAlerts(array $queries): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/alerts', $this->headers(), ['queries' => $queries]);
        $this->assertSame(200, $response['headers']['status-code']);

        return $response['body'];
    }

    private function setThreshold(string $monitorId, float|int $threshold): void
    {
        $response = $this->client->call(Client::METHOD_PUT, '/monitors/usage/' . $monitorId, $this->headers(), [
            'rules' => [Query::greaterThan('network.outbound', $threshold)->toString()],
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForStatus(string $monitorId, string $status, ?array $headers = null): array
    {
        $monitor = [];
        $headers ??= $this->headers();
        $this->assertEventually(function () use ($monitorId, $status, $headers, &$monitor) {
            $response = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $headers);
            $this->assertSame(200, $response['headers']['status-code']);
            $this->assertSame($status, $response['body']['status'], 'Monitor ' . $monitorId . ' last evaluated as ' . \json_encode($response['body']));
            $monitor = $response['body'];
        }, self::TIMEOUT_MS, 2_000);

        return $monitor;
    }

    /**
     * Waits for the monitor to be evaluated after `$after` (any evaluation when null).
     */
    private function waitForEvaluation(string $monitorId, ?string $after): string
    {
        $evaluatedAt = '';
        $this->assertEventually(function () use ($monitorId, $after, &$evaluatedAt) {
            $monitor = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers())['body'];
            $this->assertNotNull($monitor['evaluatedAt']);
            $this->assertNotSame($after, $monitor['evaluatedAt']);
            $evaluatedAt = $monitor['evaluatedAt'];
        }, self::TIMEOUT_MS, 1_000);

        return $evaluatedAt;
    }

    /**
     * @return array<string, string>
     */
    private function consoleHeaders(): array
    {
        return [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ];
    }

    /**
     * Invites a new console user to the organization with `$roles` and confirms them.
     *
     * @param list<string> $roles
     * @return string the member's email
     */
    private function addMember(string $teamId, array $roles): string
    {
        $email = 'member-' . \uniqid() . '@localhost.test';
        $membership = $this->client->call(Client::METHOD_POST, '/teams/' . $teamId . '/memberships', $this->consoleHeaders(), [
            'email' => $email,
            'roles' => $roles,
            'url' => 'http://localhost:5000/join-us#title',
        ]);
        $this->assertSame(201, $membership['headers']['status-code']);
        $invite = $this->extractQueryParamsFromEmailLink($this->getLastEmailByAddress($email, function (array $email) {
            $this->assertStringContainsString('/join-us', (string) ($email['html'] ?? ''));
        })['html']);
        $confirmed = $this->client->call(Client::METHOD_PATCH, '/teams/' . $teamId . '/memberships/' . $membership['body']['$id'] . '/status', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'userId' => $invite['userId'],
            'secret' => $invite['secret'],
        ]);
        $this->assertSame(200, $confirmed['headers']['status-code']);

        return $email;
    }

    /**
     * Subjects of the alert emails `$address` received for the monitor named `$name`.
     *
     * @return list<string>
     */
    private function emailSubjects(string $address, string $name): array
    {
        $subjects = [];
        foreach (\json_decode((string) \file_get_contents('http://maildev:1080/email'), true) ?: [] as $email) {
            $to = \array_column($email['to'] ?? [], 'address');
            if (\in_array($address, $to, true) && \str_contains((string) ($email['subject'] ?? ''), ' ' . $name . ' · ')) {
                $subjects[] = (string) $email['subject'];
            }
        }

        return $subjects;
    }

    /**
     * Posts or deletes an unsubscribe with `$token`, as the console page does.
     *
     * @return array<string, mixed>
     */
    private function unsubscribe(string $method, string $token): array
    {
        return $this->client->call($method, '/console/alerts/unsubscribe', [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], ['token' => $token]);
    }

    /**
     * The channels the alerts worker sends the user when the paused monitor
     * fires and resolves, evaluated in-process.
     *
     * @return list<string>
     */
    private function ownerChannels(string $monitorId, string $userId): array
    {
        $channels = [];
        $this->inCoroutine(function () use ($monitorId, $userId, &$channels): void {
            // Each check runs a day after the last, so the cooldown never holds the firing back.
            $at = new \DateTimeImmutable('+' . (++$this->checks) . ' days');
            $messages = [];
            $this->evaluateMonitor($this->platform(), $monitorId, 20, messages: $messages, now: $at);
            $this->evaluateMonitor($this->platform(), $monitorId, 0, messages: $messages, now: $at->modify('+1 minute'));
            foreach ($messages as $message) {
                foreach ($message->recipients as $recipient) {
                    if (($recipient['resourceId'] ?? '') === $userId) {
                        $channels[] = $recipient['channel'];
                    }
                }
            }
        });
        $channels = \array_values(\array_unique($channels));
        \sort($channels);

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    private function getMonitorBody(string $monitorId): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/monitors/' . $monitorId, $this->headers());
        $this->assertSame(200, $response['headers']['status-code']);

        return $response['body'];
    }

    /**
     * A project webhook on both alert events, posting to a path of its own.
     *
     * @return array{path: string, url: string, secret: string}
     */
    private function createAlertWebhook(): array
    {
        $path = '/alerts/' . \uniqid();
        $url = 'http://request-catcher-webhook:5000' . $path;
        $webhook = $this->client->call(Client::METHOD_POST, '/webhooks', $this->headers(), [
            'webhookId' => 'unique()',
            'name' => 'Alerts ' . $path,
            'events' => ['monitors.*.alerts.*.firing', 'monitors.*.alerts.*.resolved'],
            'url' => $url,
            'tls' => false,
        ]);
        $this->assertSame(201, $webhook['headers']['status-code'], \json_encode($webhook['body']));

        return ['path' => $path, 'url' => $url, 'secret' => $webhook['body']['secret']];
    }

    /**
     * Alert statuses the monitor's events delivered to `$path`, oldest first. Every
     * webhook of the project gets every monitor's events, so they are told apart by the alert.
     *
     * @return list<string|null>
     */
    private function eventStatuses(string $path, string $monitorId): array
    {
        $statuses = [];
        foreach (\json_decode((string) \file_get_contents('http://request-catcher-webhook:5000/__find_request__?url=' . \urlencode($path)), true) ?: [] as $request) {
            $alert = \json_decode($request['data'] ?? '{}', true);
            if (($alert['monitorId'] ?? null) === $monitorId) {
                $statuses[] = $alert['status'] ?? null;
            }
        }

        return $statuses;
    }

    /**
     * Waits for the `$count`th event of the monitor's alerts with `$status` to reach `$path`.
     *
     * @return array<string, mixed> the request the catcher recorded, with its raw `body` and decoded `data`
     */
    private function waitForEvent(string $path, string $monitorId, string $status, int $count = 1): array
    {
        $this->assertEventually(function () use ($path, $monitorId, $status, $count) {
            $this->assertGreaterThanOrEqual($count, \count(\array_keys($this->eventStatuses($path, $monitorId), $status, true)), "No '{$status}' event of monitor {$monitorId} reached {$path}");
        }, 60_000, 1_000);

        return $this->getLastRequestForProject(
            $this->getProject()['$id'],
            self::REQUEST_TYPE_WEBHOOK,
            ['url' => $path],
            0,
            0,
            function (array $request) use ($monitorId, $status) {
                $this->assertSame($monitorId, $request['data']['monitorId'] ?? null);
                $this->assertSame($status, $request['data']['status'] ?? null);
            },
        );
    }

    /**
     * How many firing and resolved notifications of the monitor named `$name`
     * the organization's owner has in the console.
     *
     * @return array{firing: int, resolved: int}
     */
    private function teamNotifications(string $name): array
    {
        $bell = $this->client->call(Client::METHOD_GET, '/notifications', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ], [
            'queries' => [
                Query::equal('projectId', [$this->getProject()['$id']])->toString(),
                Query::orderDesc('$createdAt')->toString(),
                Query::limit(100)->toString(),
            ],
        ]);
        $this->assertSame(200, $bell['headers']['status-code']);

        $titles = \array_column($bell['body']['notifications'], 'title');

        return [
            'firing' => \count(\array_filter($titles, fn (string $title) => \str_starts_with($title, '[Firing] ' . $name . ' · '))),
            'resolved' => \count(\array_filter($titles, fn (string $title) => \str_starts_with($title, '[Resolved] ' . $name . ' · '))),
        ];
    }
}
