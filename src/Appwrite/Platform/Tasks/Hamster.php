<?php

namespace Appwrite\Platform\Tasks;

use Exception;
use Utopia\App;
use Utopia\Platform\Action;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use League\Csv\Writer;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\Analytics\Adapter\Mixpanel;
use Utopia\Analytics\Event;
use Utopia\Database\Document;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;

class Hamster extends Action
{
    private array $usageStats = [
        'files' => 'files.$all.count.total',
        'buckets' => 'buckets.$all.count.total',
        'databases' => 'databases.$all.count.total',
        'documents' => 'documents.$all.count.total',
        'collections' => 'collections.$all.count.total',
        'storage' => 'project.$all.storage.size',
        'requests' => 'project.$all.network.requests',
        'bandwidth' => 'project.$all.network.bandwidth',
        'users' => 'users.$all.count.total',
        'sessions' => 'sessions.email.requests.create',
        'executions' => 'executions.$all.compute.total',
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
            ->inject('register')
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->callback(function (Registry $register, Group $pools, Cache $cache, Database $dbForConsole) {
                $this->action($register, $pools, $cache, $dbForConsole);
            });
    }

    private function getStats(Database $dbForConsole, Database $dbForProject, Document $project): array
    {
        $stats = [];

        $stats['time'] = microtime(true);

        /** Get Project ID */
        $stats['projectId'] = $project->getId();

        /** Get Project Name */
        $stats['projectName'] = $project->getAttribute('name');

        /** Get Total Functions */
        $stats['functions'] = $dbForProject->count('functions', [], APP_LIMIT_COUNT);

        /** Get Total Deployments */
        $stats['deployments'] = $dbForProject->count('deployments', [], APP_LIMIT_COUNT);

        /** Get Total Members */
        $teamInternalId = $project->getAttribute('teamInternalId', null);
        if ($teamInternalId) {
            $stats['members'] = $dbForConsole->count('memberships', [
                Query::equal('teamInternalId', [$teamInternalId])
            ], APP_LIMIT_COUNT);
        } else {
            $stats['members'] = 0;
        }

        /** Get Email and Name of the project owner */
        if ($teamInternalId) {
            $membership = $dbForConsole->findOne('memberships', [
                Query::equal('teamInternalId', [$teamInternalId]),
            ]);

            $userInternalId = $membership->getAttribute('userInternalId', null);
            if ($userInternalId) {
                $user = $dbForConsole->findOne('users', [
                    Query::equal('_id', [$userInternalId]),
                ]);

                $stats['email'] = $user->getAttribute('email', null);
                $stats['name'] = $user->getAttribute('name', null);
            }
        }

        /** Get Domains */
        $stats['domains'] = $dbForProject->count('domains', [], APP_LIMIT_COUNT);

        /** Get Platforms */
        $platforms = $dbForConsole->find('platforms', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::limit(APP_LIMIT_COUNT)
        ]);

        $stats['platforms_web'] = sizeof(array_filter($platforms, function ($platform) {
            return $platform['type'] === 'web';
        }));

        $stats['platforms_android'] = sizeof(array_filter($platforms, function ($platform) {
            return $platform['type'] === 'android';
        }));

        $stats['platforms_iOS'] = sizeof(array_filter($platforms, function ($platform) {
            return str_contains($platform['type'], 'apple');
        }));

        $stats['platforms_flutter'] = sizeof(array_filter($platforms, function ($platform) {
            return str_contains($platform['type'], 'flutter');
        }));

        /** Get Usage stats */
        $range = '90d';
        $periods = [
            '90d' => [
                'period' => '1d',
                'limit' => 90,
            ],
        ];

        $metrics = array_values($this->usageStats);
        Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $limit = $periods[$range]['limit'];
                $period = $periods[$range]['period'];

                $requestDocs = $dbForProject->find('stats', [
                    Query::equal('period', [$period]),
                    Query::equal('metric', [$metric]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);

                $stats[$metric] = [];
                foreach ($requestDocs as $requestDoc) {
                    $stats[$metric][] = [
                        'value' => $requestDoc->getAttribute('value'),
                        'date' => $requestDoc->getAttribute('time'),
                    ];
                }

                $stats[$metric] = array_reverse($stats[$metric]);
                // Calculate aggregate of each metric
                $stats[$metric] = array_sum(array_column($stats[$metric], 'value'));
            }
        });

        return $stats;
    }

    public function action(Registry $register, Group $pools, Cache $cache, Database $dbForConsole): void
    {

        Console::title('Cloud Hamster V1');
        Console::success(APP_NAME . ' cloud hamster process has started');

        $sleep = (int) App::getEnv('_APP_HAMSTER_INTERVAL', '30'); // 30 seconds (by default)

        $jobInitTime = '22:00'; // (hour:minutes)
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

        Console::loop(function () use ($register, $pools, $cache, $dbForConsole, $sleep) {
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Getting Cloud Usage Stats every {$sleep} seconds");
            $loopStart = microtime(true);

            /* Initialise new Utopia app */
            $app = new App('UTC');
            $console = $app->getResource('console');

            /** Database connections */
            $totalProjects = $dbForConsole->count('projects') + 1;
            Console::success("Found a total of: {$totalProjects} projects");

            $projects = [$console];
            $count = 0;
            $limit = 30;
            $sum = 30;
            $offset = 0;
            while (!empty($projects)) {
                foreach ($projects as $project) {
                    /**
                     * Skip user projects with id 'console'
                     */
                    if ($project->getId() === 'console') {
                        continue;
                    }

                    Console::info("Getting stats for {$project->getId()}");

                    try {
                        $db = $project->getAttribute('database');
                        $adapter = $pools
                            ->get($db)
                            ->pop()
                            ->getResource();

                        $dbForProject = new Database($adapter, $cache);
                        $dbForProject->setDefaultDatabase('appwrite');
                        $dbForProject->setNamespace('_' . $project->getInternalId());

                        $statsPerProject = $this->getStats($dbForConsole, $dbForProject, $project);

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
                            ->setName('Appwrite Cloud Project Stats')
                            ->setProps($statsPerProject);
                        $res = $this->mixpanel->createEvent($event);
                        if (!$res) {
                            Console::error('Failed to create event for project: ' . $project->getId());
                        }
                    } catch (\Throwable $th) {
                        throw $th;
                        Console::error('Failed to update project ("' . $project->getId() . '") version with error: ' . $th->getMessage());
                    } finally {
                        $pools
                            ->get($db)
                            ->reclaim();
                    }
                }

                $sum = \count($projects);

                $projects = $dbForConsole->find('projects', [
                    Query::limit($limit),
                    Query::offset($offset),
                ]);

                $offset = $offset + $limit;
                $count = $count + $sum;

                Console::log('Iterated through ' . $count . '/' . $totalProjects . ' projects...');
            }


            $pools
                ->get('console')
                ->reclaim();

            $loopTook = microtime(true) - $loopStart;
            $now = date('d-m-Y H:i:s', time());
            Console::info("[{$now}] Cloud Stats took {$loopTook} seconds");
        }, $sleep, $delay);
    }
}
