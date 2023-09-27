<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Network\Validator\Origin;
use Exception;
use Utopia\App;
use Utopia\Platform\Action;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Analytics\Adapter\Mixpanel;
use Utopia\Analytics\Event;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Pools\Group;

class Hamster extends Action
{
    private array $metrics = [
        'usage_files' => 'files.$all.count.total',
        'usage_buckets' => 'buckets.$all.count.total',
        'usage_databases' => 'databases.$all.count.total',
        'usage_documents' => 'documents.$all.count.total',
        'usage_collections' => 'collections.$all.count.total',
        'usage_storage' => 'project.$all.storage.size',
        'usage_requests' => 'project.$all.network.requests',
        'usage_bandwidth' => 'project.$all.network.bandwidth',
        'usage_users' => 'users.$all.count.total',
        'usage_sessions' => 'sessions.email.requests.create',
        'usage_executions' => 'executions.$all.compute.total',
    ];

    protected string $directory = '/usr/local';

    protected string $path;

    protected string $date;

    protected Mixpanel $mixpanel;

    public static function getName(): string
    {
        return 'hamster';
    }

    public function __construct()
    {
        $this->mixpanel = new Mixpanel(App::getEnv('_APP_MIXPANEL_TOKEN', ''));

        $this
            ->desc('Get stats for projects')
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->callback(function (Group $pools, Cache $cache, Database $dbForConsole) {
                $this->action($pools, $cache, $dbForConsole);
            });
    }

    private function getStatsPerProject(Group $pools, Cache $cache, Database $dbForConsole)
    {
        $this->calculateByGroup('projects', $dbForConsole, function (Database $dbForConsole, Document $project) use ($pools, $cache) {
            /**
             * Skip user projects with id 'console'
             */
            if ($project->getId() === 'console') {
                Console::info("Skipping project console");
                return;
            }

            Console::log("Getting stats for {$project->getId()}");

            try {
                $db = $project->getAttribute('database');
                $adapter = $pools
                    ->get($db)
                    ->pop()
                    ->getResource();

                $dbForProject = new Database($adapter, $cache);
                $dbForProject->setDefaultDatabase('appwrite');
                $dbForProject->setNamespace('_' . $project->getInternalId());

                $statsPerProject = [];

                $statsPerProject['time'] = microtime(true);

                /** Get Project ID */
                $statsPerProject['project_id'] = $project->getId();

                /** Get project created time */
                $statsPerProject['project_created'] = $project->getAttribute('$createdAt');

                /** Get Project Name */
                $statsPerProject['project_name'] = $project->getAttribute('name');

                /** Get Total Functions */
                $statsPerProject['custom_functions'] = $dbForProject->count('functions', [], APP_LIMIT_COUNT);

                foreach (\array_keys(Config::getParam('runtimes')) as $runtime) {
                    $statsPerProject['custom_functions_' . $runtime] = $dbForProject->count('functions', [
                        Query::equal('runtime', [$runtime]),
                    ], APP_LIMIT_COUNT);
                }

                /** Get Total Deployments */
                $statsPerProject['custom_deployments'] = $dbForProject->count('deployments', [], APP_LIMIT_COUNT);

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
                        throw new Exception('Membership not found. Skipping project : ' . $project->getId());
                    }

                    $userInternalId = $membership->getAttribute('userInternalId', null);
                    if ($userInternalId) {
                        $user = $dbForConsole->findOne('users', [
                            Query::equal('_id', [$userInternalId]),
                        ]);

                        $statsPerProject['email'] = $user->getAttribute('email', null);
                        $statsPerProject['name'] = $user->getAttribute('name', null);
                    }
                }

                /** Get Domains */
                $statsPerProject['custom_domains'] = $dbForConsole->count('domains', [
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

                Authorization::skip(function () use ($dbForProject, $periods, &$statsPerProject) {
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

                if (isset($statsPerProject['email'])) {
                    /** Send data to mixpanel */
                    $res = $this->mixpanel->createProfile($statsPerProject['email'], '', [
                        'name' => $statsPerProject['name'],
                        'email' => $statsPerProject['email']
                    ]);

                    if (!$res) {
                        Console::error('Failed to create user profile for project: ' . $project->getId());
                    }

                    $event = new Event();
                    $event
                        ->setName('Project Daily Usage')
                        ->setProps($statsPerProject);
                    $res = $this->mixpanel->createEvent($event);
                    if (!$res) {
                        Console::error('Failed to create event for project: ' . $project->getId());
                    }
                }
            } catch (Exception $e) {
                Console::error('Failed to send stats for project: ' . $project->getId());
                Console::error($e->getMessage());
            } finally {
                $pools
                    ->get($db)
                    ->reclaim();
            }
        });
    }

    public function action(Group $pools, Cache $cache, Database $dbForConsole): void
    {

        Console::title('Cloud Hamster V1');
        Console::success(APP_NAME . ' cloud hamster process has started');

        $sleep = (int) App::getEnv('_APP_HAMSTER_INTERVAL', '30'); // 30 seconds (by default)

        $jobInitTime = App::getEnv('_APP_HAMSTER_TIME', '22:00'); // (hour:minutes)
        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $next = new \DateTime($now->format("Y-m-d $jobInitTime"));
        $next->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $delay = $next->getTimestamp() - $now->getTimestamp();

        /**
         * If time passed for the target day.
         */
        if ($delay <= 0) {
            $next->add(\DateInterval::createFromDateString('1 days'));
            $delay = $next->getTimestamp() - $now->getTimestamp();
        }

        Console::log('[' . $now->format("Y-m-d H:i:s.v") . '] Delaying for ' . $delay . ' setting loop to [' . $next->format("Y-m-d H:i:s.v") . ']');

        Console::loop(function () use ($pools, $cache, $dbForConsole, $sleep) {
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Getting Cloud Usage Stats every {$sleep} seconds");
            $loopStart = microtime(true);

            /* Initialise new Utopia app */
            $app = new App('UTC');

            Console::info('Getting stats for all projects');
            $this->getStatsPerProject($pools, $cache, $dbForConsole);
            Console::success('Completed getting stats for all projects');

            Console::info('Getting stats for all organizations');
            $this->getStatsPerOrganization($dbForConsole);
            Console::success('Completed getting stats for all organizations');

            Console::info('Getting stats for all users');
            $this->getStatsPerUser($dbForConsole);
            Console::success('Completed getting stats for all users');

            $pools
                ->get('console')
                ->reclaim();

            $loopTook = microtime(true) - $loopStart;
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Cloud Stats took {$loopTook} seconds");
        }, $sleep, $delay);
    }

    protected function calculateByGroup(string $collection, Database $dbForConsole, callable $callback)
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $results = [];
        $sum = $limit;

        $executionStart = \microtime(true);

        while ($sum === $limit) {
            $chunk++;

            $results = $dbForConsole->find($collection, \array_merge([
                Query::limit($limit),
                Query::offset($count)
            ]));

            $sum = count($results);

            Console::log('Processing chunk #' . $chunk . '. Found ' . $sum . ' documents');

            foreach ($results as $document) {
                call_user_func($callback, $dbForConsole, $document);
                $count++;
            }
        }

        $executionEnd = \microtime(true);

        Console::log("Processed {$count} document by group in " . ($executionEnd - $executionStart) . " seconds");
    }

    protected function getStatsPerOrganization(Database $dbForConsole)
    {

        $this->calculateByGroup('teams', $dbForConsole, function (Database $dbForConsole, Document $document) {
            try {
                $statsPerOrganization = [];

                /** Organization name */
                $statsPerOrganization['name'] = $document->getAttribute('name');

                /** Get Email and of the organization owner */
                $membership = $dbForConsole->findOne('memberships', [
                    Query::equal('teamInternalId', [$document->getInternalId()]),
                ]);

                if (!$membership || $membership->isEmpty()) {
                    throw new Exception('Membership not found. Skipping organization : ' . $document->getId());
                }

                $userInternalId = $membership->getAttribute('userInternalId', null);
                if ($userInternalId) {
                    $user = $dbForConsole->findOne('users', [
                        Query::equal('_id', [$userInternalId]),
                    ]);

                    $statsPerOrganization['email'] = $user->getAttribute('email', null);
                }

                /** Organization Creation Date */
                $statsPerOrganization['created'] = $document->getAttribute('$createdAt');

                /** Number of team members */
                $statsPerOrganization['members'] = $document->getAttribute('total');

                /** Number of projects in this organization */
                $statsPerOrganization['projects'] = $dbForConsole->count('projects', [
                    Query::equal('teamId', [$document->getId()]),
                    Query::limit(APP_LIMIT_COUNT)
                ]);

                if (!isset($statsPerOrganization['email'])) {
                    throw new Exception('Email not found. Skipping organization : ' . $document->getId());
                }

                $event = new Event();
                $event
                    ->setName('Organization Daily Usage')
                    ->setProps($statsPerOrganization);
                $res = $this->mixpanel->createEvent($event);
                if (!$res) {
                    throw new Exception('Failed to create event for organization : ' . $document->getId());
                }
            } catch (Exception $e) {
                Console::error($e->getMessage());
            }
        });
    }

    protected function getStatsPerUser(Database $dbForConsole)
    {
        $this->calculateByGroup('users', $dbForConsole, function (Database $dbForConsole, Document $document) {
            try {
                $statsPerUser = [];

                /** Organization name */
                $statsPerUser['name'] = $document->getAttribute('name');

                /** Organization ID (needs to be stored as an email since mixpanel uses the email attribute as a distinctID) */
                $statsPerUser['email'] = $document->getAttribute('email');

                /** Organization Creation Date */
                $statsPerUser['created'] = $document->getAttribute('$createdAt');

                /** Number of teams this user is a part of */
                $statsPerUser['memberships'] = $dbForConsole->count('memberships', [
                    Query::equal('userInternalId', [$document->getInternalId()]),
                    Query::limit(APP_LIMIT_COUNT)
                ]);

                if (!isset($statsPerUser['email'])) {
                    throw new Exception('User has no email: ' . $document->getId());
                }

                /** Send data to mixpanel */
                $event = new Event();
                $event
                    ->setName('User Daily Usage')
                    ->setProps($statsPerUser);
                $res = $this->mixpanel->createEvent($event);

                if (!$res) {
                    throw new Exception('Failed to create user profile for user: ' . $document->getId());
                }
            } catch (Exception $e) {
                Console::error($e->getMessage());
            }
        });
    }
}
