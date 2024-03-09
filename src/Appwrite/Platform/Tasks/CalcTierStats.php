<?php

namespace Appwrite\Platform\Tasks;

use League\Csv\Writer;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;
use Utopia\Validator\Text;

class CalcTierStats extends Action
{
    /*
     * Csv cols headers
     */
    private array $columns = [
        'Project ID',
        'Organization ID',
        'Organization Email',
        'Organization Members',
        'Teams',
        'Users',
        'Requests',
        'Bandwidth',
        'Domains',
        'Api keys',
        'Webhooks',
        'Platforms',
        'Buckets',
        'Files',
        'Storage (bytes)',
        'Max File Size (bytes)',
        'Databases',
        'Functions',
        'Deployments',
        'Executions',
        'Migrations',
    ];

    protected string $directory = '/usr/local';
    protected string $path;
    protected string $date;

    private array $usageStats = [
        'network.requests'  => 'Requests',
        'network.inbound'   => 'Inbound',
        'network.outbound'  => 'Outbound',

    ];

    public static function getName(): string
    {
        return 'calc-tier-stats';
    }

    public function __construct()
    {

        $this
            ->desc('Get stats for projects')
            ->param('after', '', new Text(36), 'After cursor', true)
            ->param('projectId', '', new Text(36), 'Select project to validate', true)
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->inject('getProjectDB')
            ->inject('storage')
            ->callback(function ($after, $projectId, Group $pools, Cache $cache, Database $dbForConsole, callable $getProjectDB, Registry $register) {
                $this->action($after, $projectId, $pools, $cache, $dbForConsole, $getProjectDB, $register);
            });
    }


    public function action(string $after, string $projectId, Group $pools, Cache $cache, Database $dbForConsole, callable $getProjectDB, Registry $register): void
    {
        //docker compose exec -t appwrite calc-tier-stats

         Console::title('Migration trigger');
         Console::success(APP_NAME . ' Migration trigger');





    }



//        Console::title('Cloud free tier  stats calculation V1');
//        Console::success(APP_NAME . ' cloud free tier  stats calculation has started');
//
//        /** CSV stuff */
//        $this->date = date('Y-m-d');
//        $this->path = "{$this->directory}/tier_stats_{$this->date}.csv";
//        $csv = Writer::createFromPath($this->path, 'w');
//        $csv->insertOne($this->columns);
//
//        if (!empty($projectId)) {
//            try {
//                console::log("Project " . $projectId);
//                $project = $dbForConsole->getDocument('projects', $projectId);
//                $dbForProject = call_user_func($getProjectDB, $project);
//                $data = $this->getData($project, $dbForConsole, $dbForProject);
//                $csv->insertOne($data);
//                $this->sendMail($register);
//
//                return;
//            } catch (\Throwable $th) {
//                Console::error("Unexpected error occured with Project ID {$projectId}");
//                Console::error('[Error] Type: ' . get_class($th));
//                Console::error('[Error] Message: ' . $th->getMessage());
//                Console::error('[Error] File: ' . $th->getFile());
//                Console::error('[Error] Line: ' . $th->getLine());
//            }
//        }
//
//        $queries = [];
//
//        if (!empty($after)) {
//            Console::info("Iterating remaining projects after project with ID {$after}");
//            $project = $dbForConsole->getDocument('projects', $after);
//            $queries = [Query::cursorAfter($project)];
//        } else {
//            Console::info("Iterating all projects");
//        }
//
//        $this->foreachDocument($dbForConsole, 'projects', $queries, function (Document $project) use ($getProjectDB, $dbForConsole, $csv) {
//            $projectId = $project->getId();
//            console::log("Project " . $projectId);
//            try {
//                $dbForProject = call_user_func($getProjectDB, $project);
//                $data = $this->getData($project, $dbForConsole, $dbForProject);
//                $csv->insertOne($data);
//            } catch (\Throwable $th) {
//                Console::error("Unexpected error occured with Project ID {$projectId}");
//                Console::error('[Error] Type: ' . get_class($th));
//                Console::error('[Error] Message: ' . $th->getMessage());
//                Console::error('[Error] File: ' . $th->getFile());
//                Console::error('[Error] Line: ' . $th->getLine());
//            }
//        });
//
//        $this->sendMail($register);
//    }
//
//    private function foreachDocument(Database $database, string $collection, array $queries = [], callable $callback = null): void
//    {
//        $limit = 1000;
//        $results = [];
//        $sum = $limit;
//        $latestDocument = null;
//
//        while ($sum === $limit) {
//            $newQueries = $queries;
//
//            if ($latestDocument != null) {
//                array_unshift($newQueries, Query::cursorAfter($latestDocument));
//            }
//            $newQueries[] = Query::limit($limit);
//            $results = $database->find('projects', $newQueries);
//
//            if (empty($results)) {
//                return;
//            }
//
//            $sum = count($results);
//
//            foreach ($results as $document) {
//                if (is_callable($callback)) {
//                    $callback($document);
//                }
//            }
//            $latestDocument = $results[array_key_last($results)];
//        }
//    }
//
//    private function sendMail(Registry $register): void
//    {
//        /** @var PHPMailer $mail */
//        $mail = $register->get('smtp');
//        $mail->clearAddresses();
//        $mail->clearAllRecipients();
//        $mail->clearReplyTos();
//        $mail->clearAttachments();
//        $mail->clearBCCs();
//        $mail->clearCCs();
//
//        try {
//            /** Addresses */
//            $mail->setFrom(App::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM), 'Appwrite Cloud Hamster');
//            $recipients = explode(',', App::getEnv('_APP_USERS_STATS_RECIPIENTS', ''));
//            foreach ($recipients as $recipient) {
//                $mail->addAddress($recipient);
//            }
//
//            /** Attachments */
//            $mail->addAttachment($this->path);
//
//            /** Content */
//            $mail->Subject = "Cloud Report for {$this->date}";
//            $mail->Body = "Please find the daily cloud report atttached";
//            $mail->send();
//            Console::success('Email has been sent!');
//        } catch (\Throwable $e) {
//            Console::error("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
//        }
//    }
//
//
//    private function getData(Document $project, Database $dbForConsole, Database $dbForProject): array
//    {
//        $stats['Project ID'] = $project->getId();
//        $stats['Organization ID']   = $project->getAttribute('teamId', null);
//
//        $teamInternalId = $project->getAttribute('teamInternalId', null);
//
//        if ($teamInternalId) {
//            $membership = $dbForConsole->findOne('memberships', [
//                Query::equal('teamInternalId', [$teamInternalId]),
//            ]);
//
//            if (!$membership || $membership->isEmpty()) {
//                Console::error('Membership not found. Skipping project : ' . $project->getId());
//            }
//
//            $userId = $membership->getAttribute('userId', null);
//            if ($userId) {
//                $user = $dbForConsole->getDocument('users', $userId);
//                $stats['Organization Email'] = $user->getAttribute('email', null);
//            }
//        } else {
//            Console::error("Email was not found for this Organization ID :{$teamInternalId}");
//        }
//
//        /** Get Total Members */
//        if ($teamInternalId) {
//            $stats['Organization Members'] = $dbForConsole->count('memberships', [
//                Query::equal('teamInternalId', [$teamInternalId])
//            ]);
//        } else {
//            $stats['Organization Members'] = 0;
//        }
//
//        /** Get Total internal Teams */
//        try {
//            $stats['Teams'] = $dbForProject->count('teams', []);
//        } catch (\Throwable) {
//            $stats['Teams'] = 0;
//        }
//
//        /** Get Total users */
//        try {
//            $stats['Users'] = $dbForProject->count('users', []);
//        } catch (\Throwable) {
//            $stats['Users'] = 0;
//        }
//
//        /** Get Usage stats */
//        $range = '30d';
//        $periods = [
//            '30d' => [
//                'period' => '1d',
//                'limit' => 30,
//            ]
//        ];
//
//        $tmp = [];
//        $metrics = $this->usageStats;
//        Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$tmp) {
//            foreach ($metrics as $metric => $name) {
//                $limit = $periods[$range]['limit'];
//                $period = $periods[$range]['period'];
//
//                $requestDocs = $dbForProject->find('stats', [
//                    Query::equal('metric', [$metric]),
//                    Query::equal('period', [$period]),
//                    Query::limit($limit),
//                    Query::orderDesc('time'),
//                ]);
//
//                $tmp[$metric] = [];
//                foreach ($requestDocs as $requestDoc) {
//                    if (empty($requestDoc)) {
//                        continue;
//                    }
//
//                    $tmp[$metric][] = [
//                        'value' => $requestDoc->getAttribute('value'),
//                        'date' => $requestDoc->getAttribute('time'),
//                    ];
//                }
//
//                $tmp[$metric] = array_reverse($tmp[$metric]);
//                $tmp[$metric] = array_sum(array_column($tmp[$metric], 'value'));
//            }
//        });
//
//        foreach ($tmp as $key => $value) {
//            $stats[$metrics[$key]]  = $value;
//        }
//
//        /**
//         * Workaround to combine network.inbound+network.outbound as network.
//         */
//        $stats['Bandwidth'] = ($stats['Inbound'] ?? 0) + ($stats['Outbound'] ?? 0);
//        unset($stats['Inbound']);
//        unset($stats['Outbound']);
//
//        try {
//            /** Get Domains */
//            $stats['Domains'] = $dbForConsole->count('rules', [
//                Query::equal('projectInternalId', [$project->getInternalId()]),
//            ]);
//        } catch (\Throwable) {
//            $stats['Domains'] = 0;
//        }
//
//        try {
//            /** Get Api keys */
//            $stats['Api keys'] = $dbForConsole->count('keys', [
//                Query::equal('projectInternalId', [$project->getInternalId()]),
//            ]);
//        } catch (\Throwable) {
//            $stats['Api keys'] = 0;
//        }
//
//        try {
//            /** Get Webhooks */
//            $stats['Webhooks'] = $dbForConsole->count('webhooks', [
//                Query::equal('projectInternalId', [$project->getInternalId()]),
//            ]);
//        } catch (\Throwable) {
//            $stats['Webhooks'] = 0;
//        }
//
//        try {
//            /** Get Platforms */
//            $stats['Platforms'] = $dbForConsole->count('platforms', [
//                Query::equal('projectInternalId', [$project->getInternalId()]),
//            ]);
//        } catch (\Throwable) {
//            $stats['Platforms'] = 0;
//        }
//
//        /** Get Files & Buckets */
//        $filesCount = 0;
//        $filesSum = 0;
//        $maxFileSize = 0;
//        $counter = 0;
//        try {
//            $buckets = $dbForProject->find('buckets', []);
//            foreach ($buckets as $bucket) {
//                $file = $dbForProject->findOne('bucket_' . $bucket->getInternalId(), [Query::orderDesc('sizeOriginal'),]);
//                if (empty($file)) {
//                    continue;
//                }
//                $filesSum   += $dbForProject->sum('bucket_' . $bucket->getInternalId(), 'sizeOriginal', []);
//                $filesCount += $dbForProject->count('bucket_' . $bucket->getInternalId(), []);
//                if ($file->getAttribute('sizeOriginal') > $maxFileSize) {
//                    $maxFileSize = $file->getAttribute('sizeOriginal');
//                }
//                $counter++;
//            }
//        } catch (\Throwable $t) {
//            Console::error("Error while counting buckets: {$project->getId()}");
//        }
//        $stats['Buckets'] = $counter;
//        $stats['Files'] = $filesCount;
//        $stats['Storage (bytes)'] = $filesSum;
//        $stats['Max File Size (bytes)'] = $maxFileSize;
//
//
//        try {
//            /** Get Total Functions */
//            $stats['Databases'] = $dbForProject->count('databases', []);
//        } catch (\Throwable) {
//            $stats['Databases'] = 0;
//        }
//
//        /** Get Total Functions */
//        try {
//            $stats['Functions'] = $dbForProject->count('functions', []);
//        } catch (\Throwable) {
//            $stats['Functions'] = 0;
//        }
//
//        /** Get Total Deployments */
//        try {
//            $stats['Deployments'] = $dbForProject->count('deployments', []);
//        } catch (\Throwable) {
//            $stats['Deployments'] = 0;
//        }
//
//        /** Get Total Executions */
//        try {
//            $stats['Executions'] = $dbForProject->count('executions', []);
//        } catch (\Throwable) {
//            $stats['Executions'] = 0;
//        }
//
//        /** Get Total Migrations */
//        try {
//            $stats['Migrations'] = $dbForProject->count('migrations', []);
//        } catch (\Throwable) {
//            $stats['Migrations'] = 0;
//        }
//
//        return array_values($stats);
//    }
}
