<?php

namespace Appwrite\Platform\Tasks;

use Exception;
use Utopia\App;
use Utopia\Platform\Action;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Query;
use League\Csv\Writer;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;

class CalcUsersStats extends Action
{
    private array $columns = [
        'Project ID',
        'Project Name',
        'Team ID',
         'Team name',
         'Users'
    ];

    protected string $directory = '/usr/local';
    protected string $path;
    protected string $date;

    public static function getName(): string
    {
        return 'calc-users-stats';
    }

    public function __construct()
    {

        $this
            ->desc('Get stats for projects')
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->inject('register')
            ->callback(function (Group $pools, Cache $cache, Database $dbForConsole, Registry $register) {
                $this->action($pools, $cache, $dbForConsole, $register);
            });
    }

    public function action(Group $pools, Cache $cache, Database $dbForConsole, Registry $register): void
    {
        //docker compose exec -t appwrite calc-users-stats

        Console::title('Cloud Users calculation V1');
        Console::success(APP_NAME . ' cloud Users calculation has started');

        /* Initialise new Utopia app */
        $app = new App('UTC');
        $console = $app->getResource('console');

        /** CSV stuff */
        $this->date = date('Y-m-d');
        $this->path = "{$this->directory}/users_stats_{$this->date}.csv";
        $csv = Writer::createFromPath($this->path, 'w');
        $csv->insertOne($this->columns);

        /** Database connections */
        $totalProjects = $dbForConsole->count('projects');
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

                    /** Get Project ID */
                    $stats['Project ID'] = $project->getId();

                    /** Get Project Name */
                    $stats['Project Name'] = $project->getAttribute('name');


                    /** Get Team Name and Id */
                    $teamId = $project->getAttribute('teamId', null);
                    if ($teamId) {
                        $team = $dbForConsole->getDocument('teams', $teamId);
                    }

                    $stats['Team ID'] = $team->getId() ?? 'N/A';
                    $stats['Team name'] = $team->getAttribute('name', 'N/A');

                    /** Get Total Users */
                    $stats['users'] = $dbForProject->count('users', [], APP_LIMIT_COUNT);

                    $csv->insertOne(array_values($stats));
                } catch (\Throwable $th) {
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
        }
        Console::log('Iterated through ' . $count - 1 . '/' . $totalProjects . ' projects...');
        $pools
            ->get('console')
            ->reclaim();

        /** @var PHPMailer $mail */
        $mail = $register->get('smtp');

        $mail->clearAddresses();
        $mail->clearAllRecipients();
        $mail->clearReplyTos();
        $mail->clearAttachments();
        $mail->clearBCCs();
        $mail->clearCCs();

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
