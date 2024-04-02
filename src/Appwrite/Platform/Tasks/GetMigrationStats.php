<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Utopia\Queue\Connections;
use League\Csv\CannotInsertRecord;
use League\Csv\Writer;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization as ValidatorAuthorization;
use Utopia\Http\Adapter\FPM\Server;
use Utopia\Http\Http;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Registry\Registry;
use Utopia\System\System;

class GetMigrationStats extends Action
{
    /*
     * Csv cols headers
     */
    private array $columns = [
        'Project ID',
        '$id',
        '$createdAt',
        'status',
        'stage',
        'source'
    ];

    protected string $directory = '/usr/local';
    protected string $path;
    protected string $date;

    public static function getName(): string
    {
        return 'get-migration-stats';
    }

    public function __construct()
    {

        $this
            ->desc('Get stats for projects')
            ->inject('pools')
            ->inject('cache')
            ->inject('dbForConsole')
            ->inject('register')
            ->inject('auth')
            ->inject('connections')
            ->callback(function (Group $pools, Cache $cache, Database $dbForConsole, Registry $register, ValidatorAuthorization $auth, Connections $connections) {
                $this->action($pools, $cache, $dbForConsole, $register, $auth, $connections);
            });
    }

    /**
     * @throws \Utopia\Exception
     * @throws CannotInsertRecord
     */
    public function action(Group $pools, Cache $cache, Database $dbForConsole, Registry $register, ValidatorAuthorization $auth, Connections $connections): void
    {
        //docker compose exec -t appwrite get-migration-stats

        Console::title('Migration stats calculation V1');
        Console::success(APP_NAME . ' Migration stats calculation has started');

        /* Initialise new Utopia app */
        $http = new Http(new Server(), 'UTC');
        $console = $http->getResource('console');

        /** CSV stuff */
        $this->date = date('Y-m-d');
        $this->path = "{$this->directory}/migration_stats_{$this->date}.csv";
        $csv = Writer::createFromPath($this->path, 'w');
        $csv->insertOne($this->columns);

        /** Database connections */
        $totalProjects = $dbForConsole->count('projects');
        Console::success("Found a total of: {$totalProjects} projects");

        $projects = [$console];
        $count = 0;
        $limit = 100;
        $sum = 100;
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
                    $connection = $pools->get($db)->pop();
                    $connections->add($connection);
                    $adapter = $connection->getResource();

                    $dbForProject = new Database($adapter, $cache); // TODO: Use getProjectDB instead, or reclaim connections properly
                    $dbForProject->setAuthorization($auth);
                    $dbForProject->setDatabase('appwrite');
                    $dbForProject->setNamespace('_' . $project->getInternalId());

                    /** Get Project ID */
                    $stats['Project ID'] = $project->getId();

                    /** Get Migration details */
                    $migrations = $dbForProject->find('migrations', [
                        Query::limit(500)
                    ]);

                    $migrations = array_map(function ($migration) use ($project) {
                        return [
                            $project->getId(),
                            $migration->getAttribute('$id'),
                            $migration->getAttribute('$createdAt'),
                            $migration->getAttribute('status'),
                            $migration->getAttribute('stage'),
                            $migration->getAttribute('source'),
                        ];
                    }, $migrations);

                    if (!empty($migrations)) {
                        $csv->insertAll($migrations);
                    }
                } catch (\Throwable $th) {
                    Console::error('Failed on project ("' . $project->getId() . '") with error on File: ' . $th->getFile() . '  line no: ' . $th->getline() . ' with message: ' . $th->getMessage());
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
            $mail->setFrom(System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM), 'Appwrite Cloud Hamster');
            $recipients = explode(',', System::getEnv('_APP_USERS_STATS_RECIPIENTS', ''));

            foreach ($recipients as $recipient) {
                $mail->addAddress($recipient);
            }

            /** Attachments */
            $mail->addAttachment($this->path);

            /** Content */
            $mail->Subject = "Migration Report for {$this->date}";
            $mail->Body = "Please find the migration report atttached";
            $mail->send();
            Console::success('Email has been sent!');
        } catch (\Throwable $e) {
            Console::error("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        }
    }
}
