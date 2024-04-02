<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Hamster as EventHamster;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Queue\Connections;
use Utopia\Analytics\Adapter\Mixpanel;
use Utopia\Analytics\Event as AnalyticsEvent;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Http\Http;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Queue\Message;
use Utopia\System\System;

class Hamster extends Action
{
    private array $metrics = [
        'usage_files' => 'files',
        'usage_buckets' => 'buckets',
        'usage_databases' => 'databases',
        'usage_documents' => 'documents',
        'usage_collections' => 'collections',
        'usage_storage' => 'files.storage',
        'usage_requests' => 'network.requests',
        'usage_inbound' => 'network.inbound',
        'usage_outbound' => 'network.outbound',
        'usage_users' => 'users',
        'usage_sessions' => 'sessions',
        'usage_executions' => 'executions',
    ];

    protected Mixpanel $mixpanel;

    public static function getName(): string
    {
        return 'hamster';
    }

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        $this
            ->desc('Hamster worker')
            ->inject('message')
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->inject('auth')
            ->inject('connections')
            ->callback(fn (Message $message, Group $pools, Cache $cache, Database $dbForConsole, Authorization $auth, Connections $connections) => $this->action($message, $pools, $cache, $dbForConsole, $auth, $connections));
    }

    /**
     * @param Message $message
     * @param Group $pools
     * @param Cache $cache
     * @param Database $dbForConsole
     *
     * @return void
     * @throws \Utopia\Database\Exception
     */
    public function action(Message $message, Group $pools, Cache $cache, Database $dbForConsole, Authorization $auth, Connections $connections): void
    {
        $token = System::getEnv('_APP_MIXPANEL_TOKEN', '');
       
        if (empty($token)) {
            throw new \Exception('Missing MixPanel Token');
        }
       
        $this->mixpanel = new Mixpanel($token);

        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new \Exception('Missing payload');
        }

        $type = $payload['type'] ?? '';

        switch ($type) {
            case EventHamster::TYPE_PROJECT:
                $this->getStatsForProject(new Document($payload['project']), $pools, $cache, $dbForConsole, $auth, $connections);
                break;
            case EventHamster::TYPE_ORGANISATION:
                $this->getStatsForOrganization(new Document($payload['organization']), $dbForConsole);
                break;
            case EventHamster::TYPE_USER:
                $this->getStatsPerUser(new Document($payload['user']), $dbForConsole);
                break;
        }
    }

    /**
     * @param Document $project
     * @param Group $pools
     * @param Cache $cache
     * @param Database $dbForConsole
     * @throws \Utopia\Database\Exception
     */
    private function getStatsForProject(Document $project, Group $pools, Cache $cache, Database $dbForConsole, Authorization $auth, Connections $connections): void
    {
        /**
         * Skip user projects with id 'console'
         */
        if ($project->getId() === 'console') {
            Console::info("Skipping project console");
            return;
        }

        Console::log("Getting stats for Project {$project->getId()}");

        try {
            $db = $project->getAttribute('database');
            $connection = $pools->get($db)->pop();
            $connections->add($connection);
            $adapter = $connection->getResource();

            $dbForProject = new Database($adapter, $cache); // TODO: Use getProjectDB instead, or reclaim connections properly
            $dbForProject->setAuthorization($auth);
            $dbForProject->setDatabase('appwrite');
            $dbForProject->setNamespace('_' . $project->getInternalId());

            $statsPerProject = [];

            $statsPerProject['time'] = $project->getAttribute('$time');

            /** Get Project ID */
            $statsPerProject['project_id'] = $project->getId();

            /** Get project created time */
            $statsPerProject['project_created'] = $project->getAttribute('$createdAt');

            /** Get Project Name */
            $statsPerProject['project_name'] = $project->getAttribute('name');

            /** Total Project Variables */
            $statsPerProject['custom_variables'] = $dbForProject->count('variables', [], APP_LIMIT_COUNT);

            /** Total Migrations */
            $statsPerProject['custom_migrations'] = $dbForProject->count('migrations', [], APP_LIMIT_COUNT);

            /** Get Custom SMTP */
            $smtp = $project->getAttribute('smtp', null);
            if ($smtp) {
                $statsPerProject['custom_smtp_status'] = $smtp['enabled'] === true ? 'enabled' : 'disabled';

                /** Get Custom Templates Count */
                $templates = array_keys($project->getAttribute('templates', []));
                $statsPerProject['custom_email_templates'] = array_filter($templates, function ($template) {
                    return str_contains($template, 'email');
                });
                $statsPerProject['custom_sms_templates'] = array_filter($templates, function ($template) {
                    return str_contains($template, 'sms');
                });
            }

            /** Get total relationship attributes */
            $statsPerProject['custom_relationship_attributes'] = $dbForProject->count('attributes', [
                Query::equal('type', ['relationship'])
            ], APP_LIMIT_COUNT);

            /** Get Total Functions */
            $statsPerProject['custom_functions'] = $dbForProject->count('functions', [], APP_LIMIT_COUNT);

            foreach (\array_keys(Config::getParam('runtimes')) as $runtime) {
                $statsPerProject['custom_functions_' . $runtime] = $dbForProject->count('functions', [
                    Query::equal('runtime', [$runtime]),
                ], APP_LIMIT_COUNT);
            }

            /** Get Total Deployments */
            $statsPerProject['custom_deployments'] = $dbForProject->count('deployments', [], APP_LIMIT_COUNT);
            $statsPerProject['custom_deployments_manual'] = $dbForProject->count('deployments', [
                Query::equal('type', ['manual'])
            ], APP_LIMIT_COUNT);
            $statsPerProject['custom_deployments_git'] = $dbForProject->count('deployments', [
                Query::equal('type', ['vcs'])
            ], APP_LIMIT_COUNT);

            /** Get VCS repos connected */
            $statsPerProject['custom_vcs_repositories'] = $dbForConsole->count('repositories', [
                Query::equal('projectInternalId', [$project->getInternalId()])
            ], APP_LIMIT_COUNT);

            /** Get Total Teams */
            $statsPerProject['custom_teams'] = $dbForProject->count('teams', [], APP_LIMIT_COUNT);

            /** Get Total Members */
            $teamInternalId = $project->getAttribute('teamInternalId', null);
            if ($teamInternalId) {
                $statsPerProject['custom_organization_members'] = $dbForConsole->count('memberships', [
                    Query::equal('teamInternalId', [$teamInternalId])
                ], APP_LIMIT_COUNT);
            } else {
                $statsPerProject['custom_organization_members'] = 0;
            }

            /** Get Email and Name of the project owner */
            if ($teamInternalId) {
                $membership = $dbForConsole->findOne('memberships', [
                    Query::equal('teamInternalId', [$teamInternalId]),
                ]);

                if (!$membership || $membership->isEmpty()) {
                    throw new \Exception('Membership not found. Skipping project : ' . $project->getId());
                }

                $userId = $membership->getAttribute('userId', null);
                if ($userId) {
                    $user = $dbForConsole->getDocument('users', $userId);
                    $statsPerProject['email'] = $user->getAttribute('email', null);
                    $statsPerProject['name'] = $user->getAttribute('name', null);
                }
            }

            /** Add billing information to the project */
            $organization = $dbForConsole->findOne('teams', [
                Query::equal('$internalId', [$teamInternalId])
            ]);

            $billing = $this->getBillingDetails($organization);
            $statsPerProject['billing_plan'] = $billing['billing_plan'] ?? null;
            $statsPerProject['billing_start_date'] = $billing['billing_start_date'] ?? null;

            /** Get Domains */
            $statsPerProject['custom_domains'] = $dbForConsole->count('rules', [
                Query::equal('projectInternalId', [$project->getInternalId()]),
                Query::limit(APP_LIMIT_COUNT)
            ]);

            /** Get Platforms */
            $platforms = $dbForConsole->find('platforms', [
                Query::equal('projectInternalId', [$project->getInternalId()]),
                Query::limit(APP_LIMIT_COUNT)
            ]);

            $statsPerProject['custom_platforms_web'] = sizeof(array_filter($platforms, function ($platform) {
                return $platform['type'] === 'web';
            }));

            $statsPerProject['custom_platforms_android'] = sizeof(array_filter($platforms, function ($platform) {
                return $platform['type'] === 'android';
            }));

            $statsPerProject['custom_platforms_apple'] = sizeof(array_filter($platforms, function ($platform) {
                return str_contains($platform['type'], 'apple');
            }));

            $statsPerProject['custom_platforms_flutter'] = sizeof(array_filter($platforms, function ($platform) {
                return str_contains($platform['type'], 'flutter');
            }));

            $flutterPlatforms = [Origin::CLIENT_TYPE_FLUTTER_ANDROID, Origin::CLIENT_TYPE_FLUTTER_IOS, Origin::CLIENT_TYPE_FLUTTER_MACOS, Origin::CLIENT_TYPE_FLUTTER_WINDOWS, Origin::CLIENT_TYPE_FLUTTER_LINUX];

            foreach ($flutterPlatforms as $flutterPlatform) {
                $statsPerProject['custom_platforms_' . $flutterPlatform] = sizeof(array_filter($platforms, function ($platform) use ($flutterPlatform) {
                    return $platform['type'] === $flutterPlatform;
                }));
            }

            $statsPerProject['custom_platforms_api_keys'] = $dbForConsole->count('keys', [
                Query::equal('projectInternalId', [$project->getInternalId()]),
                Query::limit(APP_LIMIT_COUNT)
            ]);

            /** Get Usage $statsPerProject */
            $periods = [
                'infinity' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
                '24h' => [
                    'period' => '1h',
                    'limit' => 24,
                ],
            ];

            $auth->skip(function () use ($dbForProject, $periods, &$statsPerProject) {
                foreach ($this->metrics as $key => $metric) {
                    foreach ($periods as $periodKey => $periodValue) {
                        $limit = $periodValue['limit'];
                        $period = $periodValue['period'];

                        $requestDocs = $dbForProject->find('stats', [
                            Query::equal('period', [$period]),
                            Query::equal('metric', [$metric]),
                            Query::limit($limit),
                            Query::orderDesc('time'),
                        ]);

                        $statsPerProject[$key . '_' . $periodKey] = [];
                        foreach ($requestDocs as $requestDoc) {
                            $statsPerProject[$key . '_' . $periodKey][] = [
                                'value' => $requestDoc->getAttribute('value'),
                                'date' => $requestDoc->getAttribute('time'),
                            ];
                        }

                        $statsPerProject[$key . '_' . $periodKey] = array_reverse($statsPerProject[$key . '_' . $periodKey]);
                        // Calculate aggregate of each metric
                        $statsPerProject[$key . '_' . $periodKey] = array_sum(array_column($statsPerProject[$key . '_' . $periodKey], 'value'));
                    }
                }
            });

            /**
             * Workaround to combine network.Inbound+network.outbound as bandwidth.
             */
            $statsPerProject["usage_bandwidth_infinity"]  = $statsPerProject["usage_inbound_infinity"] + $statsPerProject["usage_outbound_infinity"];
            $statsPerProject["usage_bandwidth_24h"]  = $statsPerProject["usage_inbound_24h"] + $statsPerProject["usage_outbound_24h"];
            unset($statsPerProject["usage_outbound_24h"]);
            unset($statsPerProject["usage_inbound_24h"]);
            unset($statsPerProject["usage_outbound_infinity"]);
            unset($statsPerProject["usage_inbound_infinity"]);


            if (isset($statsPerProject['email'])) {
                /** Send data to mixpanel */
                $res = $this->mixpanel->createProfile($statsPerProject['email'], '', [
                    'name' => $statsPerProject['name'],
                    'email' => $statsPerProject['email']
                ]);

                if (!$res) {
                    Console::error('Failed to create user profile for project: ' . $project->getId());
                }
            }

            $event = new AnalyticsEvent();
            $event
                ->setName('Project Daily Usage')
                ->setProps($statsPerProject);
            $res = $this->mixpanel->createEvent($event);

            if (!$res) {
                Console::error('Failed to create event for project: ' . $project->getId());
            }
        } catch (\Throwable $e) {
            Console::error('Failed to send stats for project: ' . $project->getId());
            Console::error($e->getMessage());
        } finally {
            $pools
                ->get($db)
                ->reclaim();
        }
    }

    /**
     * @param Document $organization
     * @param Database $dbForConsole
     */
    private function getStatsForOrganization(Document $organization, Database $dbForConsole): void
    {
        Console::log("Getting stats for Organization {$organization->getId()}");

        try {
            $statsPerOrganization = [];

            $statsPerOrganization['time'] = $organization->getAttribute('$time');

            /** Organization name */
            $statsPerOrganization['name'] = $organization->getAttribute('name');

            /** Get Email and of the organization owner */
            $membership = $dbForConsole->findOne('memberships', [
                Query::equal('teamInternalId', [$organization->getInternalId()]),
            ]);
            if (!$membership || $membership->isEmpty()) {
                throw new \Exception('Membership not found. Skipping organization : ' . $organization->getId());
            }
            $userId = $membership->getAttribute('userId', null);
            if ($userId) {
                $user = $dbForConsole->getDocument('users', $userId);
                $statsPerOrganization['email'] = $user->getAttribute('email', null);
            }

            /** Add billing information */
            $billing = $this->getBillingDetails($organization);
            $statsPerOrganization['billing_plan'] = $billing['billing_plan'] ?? null;
            $statsPerOrganization['billing_start_date'] = $billing['billing_start_date'] ?? null;
            $statsPerOrganization['marked_for_deletion'] = $billing['markedForDeletion'] ?? 0;
            $statsPerOrganization['billing_plan_downgrade'] = $billing['billing_plan_downgrade'] ?? null;

            /** Organization Creation Date */
            $statsPerOrganization['created'] = $organization->getAttribute('$createdAt');

            /** Number of team members */
            $statsPerOrganization['members'] = $organization->getAttribute('total');

            /** Number of projects in this organization */
            $statsPerOrganization['projects'] = $dbForConsole->count('projects', [
                Query::equal('teamId', [$organization->getId()]),
                Query::limit(APP_LIMIT_COUNT)
            ]);

            if (!isset($statsPerOrganization['email'])) {
                throw new \Exception('Email not found. Skipping organization : ' . $organization->getId());
            }

            $event = new AnalyticsEvent();
            $event
                ->setName('Organization Daily Usage')
                ->setProps($statsPerOrganization);
            $res = $this->mixpanel->createEvent($event);
            if (!$res) {
                throw new \Exception('Failed to create event for organization : ' . $organization->getId());
            }
        } catch (\Throwable $e) {
            Console::error($e->getMessage());
        }
    }

    protected function getStatsPerUser(Document $user, Database $dbForConsole)
    {
        Console::log("Getting stats for User {$user->getId()}");

        try {
            $statsPerUser = [];

            $statsPerUser['time'] = $user->getAttribute('$time');

            /** Add billing information */
            $organization = $dbForConsole->findOne('teams', [
                Query::equal('userInternalId', [$user->getInternalId()])
            ]);


            $billing = $this->getBillingDetails($organization);
            $statsPerUser['billing_plan'] = $billing['billing_plan'] ?? null;
            $statsPerUser['billing_start_date'] = $billing['billing_start_date'] ?? null;

            /** Organization name */
            $statsPerUser['name'] = $user->getAttribute('name');

            /** Organization ID (needs to be stored as an email since mixpanel uses the email attribute as a distinctID) */
            $statsPerUser['email'] = $user->getAttribute('email');

            /** Organization Creation Date */
            $statsPerUser['created'] = $user->getAttribute('$createdAt');

            /** Number of teams this user is a part of */
            $statsPerUser['memberships'] = $dbForConsole->count('memberships', [
                Query::equal('userInternalId', [$user->getInternalId()]),
                Query::limit(APP_LIMIT_COUNT)
            ]);

            if (!isset($statsPerUser['email'])) {
                throw new \Exception('User has no email: ' . $user->getId());
            }

            /** Send data to mixpanel */
            $event = new AnalyticsEvent();
            $event
                ->setName('User Daily Usage')
                ->setProps($statsPerUser);

            $res = $this->mixpanel->createEvent($event);

            if (!$res) {
                throw new \Exception('Failed to create user profile for user: ' . $user->getId());
            }
        } catch (\Throwable $e) {
            Console::error($e->getMessage());
        }
    }

    private function getBillingDetails(bool|Document $team): array
    {
        $billing = [];

        if (!empty($team) && !$team->isEmpty()) {
            $billingPlan = $team->getAttribute('billingPlan', null);
            $billingPlanDowngrade = $team->getAttribute('billingPlanDowngrade', null);

            if (!empty($billingPlan) && empty($billingPlanDowngrade)) {
                $billing['billing_plan'] = $billingPlan;
            }

            if (in_array($billingPlan, ['tier-1', 'tier-2'])) {
                $billingStartDate = $team->getAttribute('billingStartDate', null);
                $billing['billing_start_date'] = $billingStartDate;
            }

            $billing['marked_for_deletion'] = $team->getAttribute('markedForDeletion', 0);
            $billing['billing_plan_downgrade'] = $billingPlanDowngrade;
        }

        return $billing;
    }
}
