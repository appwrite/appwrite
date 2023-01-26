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
use Utopia\Database\Document;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;

class Hamster extends Action
{
    private array $columns = [
        'Project ID',
        'Project Name',
        'Functions',
        'Deployments',
        'Members',
        'Domains',
        'Files',
        'Buckets',
        'Databases',
        'Documents',
        'Collections',
        'Storage',
        'Requests',
        'Bandwidth',
        'Users',
        'Sessions',
        'Executions'
    ];

    private array $usageStats = [
        'Files' => 'files.$all.count.total',
        'Buckets' => 'buckets.$all.count.total',
        'Databases' => 'databases.$all.count.total',
        'Documents' => 'documents.$all.count.total',
        'Collections' => 'collections.$all.count.total',
        'Storage' => 'project.$all.storage.size',
        'Requests' => 'project.$all.network.requests',
        'Bandwidth' => 'project.$all.network.bandwidth',
        'Users' => 'users.$all.count.total',
        'Sessions' => 'sessions.$all.requests.create',
        'Executions' => 'executions.$all.compute.total',
    ];

    protected string $directory = '/usr/local/dev';
    protected string $path;

    protected string $date;

    public static function getName(): string
    {
        return 'hamster';
    }

    public function __construct()
    {
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

        /** Get Project ID */
        $stats['Project ID'] = $project->getId();

        /** Get Project Name */
        $stats['Project Name'] = $project->getAttribute('name');

        /** Get Total Functions */
        $stats['Functions'] = $dbForProject->count('functions', [], APP_LIMIT_COUNT);

        /** Get Total Deployments */
        $stats['Deployments'] = $dbForProject->count('deployments', [], APP_LIMIT_COUNT);

        /** Get Total Members */
        $teamInternalId = $project->getAttribute('teamInternalId', null);
        if ($teamInternalId) {
            $stats['Members'] = $dbForConsole->count('memberships', [
                Query::equal('teamInternalId', [$teamInternalId])
            ], APP_LIMIT_COUNT);
        } else {
            $stats['Members'] = 0;
        }

        /** Get Domains */
        $stats['Domains'] = $dbForProject->count('domains', [], APP_LIMIT_COUNT);

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
        Console::info'Getting stats...');

        /* Initialise new Utopia app */
        $app = new App('UTC');
        $console = $app->getResource('console');

        /** CSV stuff */
        $this->date = date('Y-m-d');
        $this->path = "{$this->directory}/stats_{$this->date}.csv";
        $csv = Writer::createFromPath($this->path, 'w');
        $csv->insertOne($this->columns);

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
                    $csv->insertOne(array_values($statsPerProject));

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

        $this->sendEmail($register);
    }

    private function sendEmail(Registry $register)
    {
        /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
        $mail = $register->get('smtp');

        try {
            /** Addresses */
            $mail->setFrom(App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM), 'Appwrite Cloud Hamster');
            $recipients = explode(',', App::getEnv('_APP_HAMSTER_RECIPIENTS', ''));
            foreach ($recipients as $recipient) {
                $mail->addAddress($recipient);
            }

            /** Attachments */
            $mail->addAttachment($this->path);

            /** Content */
            $mail->Subject = "Cloud Report for {$this->date}";
            $mail->Body = "Please find the daily cloud report atttached";

            $mail->send();
            Console::success('Email has been sent!');
        } catch (Exception $e) {
            Console::error("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        }
    }
}
