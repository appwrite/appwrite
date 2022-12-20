<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Exception;
use PDO;
use Utopia\App;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;

class Stat extends Action
{
    public static function getName(): string
    {
        return 'stat';
    }

    public function __construct()
    {
        $this
            ->desc('Get stats for project')
            ->callback(fn () => $this->action());
    }

    function getConnection(string $dsn): PDO
    {
        if (empty($dsn)) {
            throw new Exception("Missing value for DSN connection");
        }
        $dsn = new DSN($dsn);
        $dsnHost = $dsn->getHost();
        $dsnPort = $dsn->getPort();
        $dsnUser = $dsn->getUser();
        $dsnPass = $dsn->getPassword();
        $dsnScheme = $dsn->getScheme();
        $dsnDatabase = $dsn->getPath();

        $connection = new PDO("mysql:host={$dsnHost};port={$dsnPort};dbname={$dsnDatabase};charset=utf8mb4", $dsnUser, $dsnPass, array(
            PDO::ATTR_TIMEOUT => 3, // Seconds
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true
        ));

        return $connection;
    }


    function getStats(Database $dbForProject): array
    {
        $range = '90d';
        $periods = [
            '90d' => [
                'period' => '1d',
                'limit' => 90,
            ],
        ];

        $metrics = [
            'files.$all.count.total',
            'buckets.$all.count.total',
            'databases.$all.count.total',
            'documents.$all.count.total',
            'collections.$all.count.total',
            'project.$all.storage.size',
            'project.$all.network.requests',
            'project.$all.network.bandwidth',
            'users.$all.count.total',
            'sessions.$all.requests.create',
            'executions.$all.compute.total',
        ];

        $stats = [];

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
                $stats[$metric . '.sum'] = array_sum(array_column($stats[$metric], 'value'));
            }
        });

        // return only the ahhggregate values
        return array_filter($stats, fn ($key) => strpos($key, '.sum') !== false, ARRAY_FILTER_USE_KEY);
    }


    public function action(): void
    {
        Console::success('Getting stats...');

        $databases = [
            'console' => [
                'type' => 'database',
                'dsns' => '',
                'multiple' => false,
                'schemes' => ['mariadb', 'mysql'],
            ],
            'projects' => [
                'type' => 'database',
                'dsns' => '',
                'multiple' => true,
                'schemes' => ['mariadb', 'mysql'],
            ],
        ];

        $dsns = explode(',', $databases['projects']['dsns']);
        $projectdsns = [];
        foreach ($dsns as &$dsn) {
            $dsn = explode('=', $dsn);
            $name = 'database' . '_' . $dsn[0];
            $dsn = $dsn[1] ?? '';
            $projectdsns[$name] = $dsn;
        }

        $cache = new Cache(new None());
        $consoledsn = explode('=', $databases['console']['dsns']);
        $consoledsn = $consoledsn[1] ?? '';
        $adapter = new MySQL($this->getConnection($consoledsn));
        $dbForConsole = new Database($adapter, $cache);
        $dbForConsole->setDefaultDatabase('appwrite');
        $dbForConsole->setNamespace('console');

        $totalProjects = $dbForConsole->count('projects') + 1;
        Console::success("Iterating through : {$totalProjects} projects");

        $app = new App('UTC');
        $console = $app->getResource('console');
        
        $projects = [$console];
        $count = 0;
        $limit = 30;
        $sum = 30;
        $offset = 0;

        $stats = [];

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
                    // TODO: Iterate through all project DBs
                    $db = $project->getAttribute('database');
                    $dsn = $projectdsns[$db] ?? '';
                    $cache = new Cache(new None());
                    $adapter = new MySQL($this->getConnection($dsn));
                    $dbForProject = new Database($adapter, $cache);
                    $dbForProject->setDefaultDatabase('appwrite');
                    $dbForProject->setNamespace('_' . $project->getInternalId());
                    $statsPerProject = $this->getStats($dbForProject);

                    foreach ($statsPerProject as $key => $value) {
                        $stats[$key] = isset($stats[$key]) ? $stats[$key] + $value : $value;
                    }

                } catch (\Throwable $th) {
                    throw $th;
                    Console::error('Failed to update project ("' . $project->getId() . '") version with error: ' . $th->getMessage());
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

        var_dump($stats);
    }
}
