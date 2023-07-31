<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Platform\Action;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Storage\Device;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Local;

class Backup extends Action
{
    public static string $mysqlDirectory = '/var/lib/mysql';
    public static string $backups = '/backups'; // Mounted volume
    protected string $containerName = 'appwrite-mariadb';
    protected string $project;

    public static function getName(): string
    {
        return 'backup';
    }

    public function __construct()
    {
        $this->checkEnvVariables();
        $this->project = explode('=', App::getEnv('_APP_CONNECTIONS_DB_PROJECT'))[0];

        $this
            ->desc('Backup a DB')
            ->callback(fn() => $this->action());
    }

    public function action(): void
    {
        self::log('--- Backup Start --- ');
        $start = microtime(true);
        $type = 'inc';
        //$type = 'full';

        switch ($type) {
            case 'inc':
                for ($i = 0; $i <= 4; $i++) {
                    $this->incrementalBackup();
                }
                break;
            case 'full':
                $time = date('Ymd_His');
                $filename = $time . '.tar.gz';
                $folder = App::getEnv('_APP_BACKUP_FOLDER');

                $local = new Local(self::$backups . '/' . $this->project . '/' . $folder . '/' . $time);
                $local->setTransferChunkSize(5  * 1024 * 1024); // > 5MB

                $backups = $local->getRoot() . '/files';
                $tarFile = $local->getRoot() . '/' . $filename;

                $this->fullBackup(
                    backups: $backups,
                );

                $this->tar(
                    folder: $backups,
                    tarFile: $tarFile,
                );

                $this->upload(
                    folder: $folder,
                    filename: $filename,
                    tarFile: $tarFile,
                    local: $local
                );

                break;

            default:
                Console::error('No type detected');
                Console::exit();
        }

        self::log('--- Backup End ' . (microtime(true) - $start) . ' seconds --- '   . PHP_EOL . PHP_EOL);
    }

    public function fullBackup(string $backups)
    {
        if (!file_exists(self::$backups)) {
            Console::error('Mount directory does not exist');
            Console::exit();
        }

        if (!file_exists($backups) && !mkdir($backups, 0755, true)) {
            Console::error('Error creating directory: ' . $backups);
            Console::exit();
        }

        $args = [
            '--user=root',
            '--password=rootsecretpassword', // todo use .env
            '--host=mariadb',
            '--backup',
            '--compress',
            //'--compress-threads=1',
            //'--no-lock', // https://docs.percona.com/percona-xtrabackup/8.0/xtrabackup-option-reference.html#-no-lock
            '--safe-slave-backup',
            '--safe-slave-backup-timeout=300',
            '--check-privileges',
            '--target-dir=' . $backups,
        ];

        $stdout = '';
        $stderr = '';
        $cmd = 'docker exec appwrite-xtrabackup xtrabackup ' . implode(' ', $args);
        self::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        //self::log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            //Console::exit();
        }

        if (!file_exists($backups . '/xtrabackup_checkpoints')) {
            Console::error('Backup failed missing files');
            Console::exit();
        }
    }

    public function tar(string $folder, string $tarFile)
    {
        $stdout = '';
        $stderr = '';
        // Tar from inside the directory for not using --strip-components
        $cmd = 'cd ' . $folder . ' && tar zcf ' . $tarFile . ' .';
        self::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        self::log($stdout);
        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        if (!file_exists($tarFile)) {
            Console::error("Can't find tar file: " . $tarFile);
            Console::exit();
        }

        $filesize = \filesize($tarFile);
        self::log("Tar file size is: " . ceil($filesize / 1024 / 1024) . 'MB');
        if ($filesize < (2 * 1024 * 1024)) {
            Console::error("File size is very small: " . $tarFile);
            Console::exit();
        }
    }

    public function upload(string $folder, string $filename, string $tarFile, Device $local)
    {
        $s3 = new DOSpaces('/' . $this->project . '/' . $folder, App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION'));

        if (!$s3->exists('/')) {
            Console::error('Can\'t read from DO ');
            Console::exit();
        }

        try {
            self::log('Uploading: ' . $tarFile);
            $destination = $s3->getRoot() . '/' . $filename;

            if (!$local->transfer($tarFile, $destination, $s3)) {
                Console::error('Error uploading to ' . $destination);
                Console::exit();
            }

            if (!$s3->exists($destination)) {
                Console::error('File not found on s3 ' . $destination);
                Console::exit();
            }
        } catch (\Exception $e) {
            Console::error($e->getMessage());
            Console::exit();
        }
    }

    public function incrementalBackup()
    {
        $project = $this->project;
        //$folder = ceil(time() / 60 * 60);
        //$folder = date('Y_m_d');
        //$folder = ceil(date('z') / 7); // day of the year 0-365
        $folder = date('W'); // week of the year  0 - 51

        $folder = 'v1_' . date('Y') . '_' . $folder;
        $local = new Local(self::$backups . '/' . $project . '/inc/' . $folder);
        $position = 1;
        $target = $local->getRoot() . '/' . $position;
        $base = null;

        if (file_exists($local->getRoot() . '/position')) {
            $position = intval(file_get_contents($local->getRoot() . '/position'));

            if (!file_exists($local->getRoot() . '/' . $position . '/xtrabackup_checkpoints')) {
                Console::error('Backup ' . $folder . ' is garbage!!!');
                Console::exit();
            }

            $position += 1;
            $base = $target;
            $target = $local->getRoot() . '/' . $position;

            Console::success($position);
        }

        if (!empty($base) && !file_exists($base) && !mkdir($base, 0755, true)) {
            Console::error('Error creating base directory: ' . $base);
            Console::exit();
        }

        if (!file_exists($target) && !mkdir($target, 0755, true)) {
            Console::error('Error creating backup directory: ' . $target);
            Console::exit();
        }

        file_put_contents($local->getRoot() . '/position', $position);

        $args = [
            '--user=root',
            '--password=rootsecretpassword',
            '--backup=1',
            '--host=mariadb',
            '--safe-slave-backup',
            '--safe-slave-backup-timeout=300',
            '--check-privileges',
            //'--no-lock',  // https://docs.percona.com/percona-xtrabackup/8.0/xtrabackup-option-reference.html#-no-lock
            //'--compress=lz4',
            //'--compress-threads=1',
            '--target-dir=' . $target
        ];

        if (!empty($base)) {
            $args[] = '--incremental-basedir=' . $base;
        }

        $stdout = '';
        $stderr = '';
        $cmd = 'docker exec appwrite-xtrabackup xtrabackup ' . implode(' ', $args);
        self::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        self::log("stdout ========= ");
        self::log($stdout);
        self::log("stdout ========= ");

        if (!empty($stderr)) {
           // Console::error($stderr);
            //Console::exit();
        }

        // For some reason they write everything as $stderr
        if (!str_contains($stderr, 'completed OK!')) {
            /// Todo We need to destroy this directory and all the data inside or move it somewhere
            Console::error($stderr);
            Console::exit();
        }
    }





//
//    /**
//     * @throws \Exception
//     */
//    public function action(): void
//    {
//        self::log('--- Backup Start --- ');
//        $this->checkEnvVariables();
//
//        $start = microtime(true);
//        $filename = date('Ymd_His') . '.tar.gz';
//        $folder = App::getEnv('_APP_BACKUP_FOLDER');
//        $project = explode('=', App::getEnv('_APP_CONNECTIONS_DB_PROJECT'))[0];
//        $s3 = new DOSpaces('/v1/' . $project . '/' . $folder, App::getEnv('_DO_SPACES_ACCESS_KEY'), App::getEnv('_DO_SPACES_SECRET_KEY'), App::getEnv('_DO_SPACES_BUCKET_NAME'), App::getEnv('_DO_SPACES_REGION'));
//        $local = new Local(self::$backups . '/' . $project . '/' . $folder);
//        $source = $local->getRoot() . '/' . $filename;
//
//        $local->setTransferChunkSize(5  * 1024 * 1024); // > 5MB
//
////        $source = '/backups/shmuel.tar.gz'; // 1452521974
////        $destination = '/shmuel/' . $time . '.tar.gz';
////
////        $local->transfer($source, $destination, $s3);
////
////        if ($s3->exists($destination)) {
////            Console::success('Uploaded successfully !!! ' . $destination);
////            Console::exit();
////        }
//
//        if (!$s3->exists('/')) {
//            Console::error('Can\'t read from DO ');
//            Console::exit();
//        }
//
//        if (!file_exists(self::$backups)) {
//            Console::error('Mount directory does not exist');
//            Console::exit();
//        }
//
//        if (!file_exists($local->getRoot()) && !mkdir($local->getRoot(), 0755, true)) {
//            Console::error('Error creating directory: ' . $local->getRoot());
//            Console::exit();
//        }
//
//        self::stopMysqlContainer($this->containerName);
//
//        Console::exit();
//
//        $stdout = '';
//        $stderr = '';
//        // Tar from inside the mysql directory for not using --strip-components
//        $cmd = 'cd ' . self::$mysqlDirectory . ' && tar zcf ' . $source . ' .';
//        self::log($cmd);
//        Console::execute($cmd, '', $stdout, $stderr);
//        self::log($stdout);
//        if (!empty($stderr)) {
//            Console::error($stderr);
//            Console::exit();
//        }
//
//
//        if (!file_exists($source)) {
//            Console::error("Can't find tar file: " . $source);
//            Console::exit();
//        }
//
//        $filesize = \filesize($source);
//        self::log("Tar file size is: " . ceil($filesize / 1024 / 1024) . 'MB');
//        if ($filesize < (2 * 1024)) {
//            Console::error("File size is very small: " . $source);
//            Console::exit();
//        }
//
//        self::startMysqlContainer($this->containerName);
//
//        try {
//            self::log('Uploading: ' . $source);
//
//            $destination = $s3->getRoot() . '/' . $filename;
//
//            if (!$local->transfer($source, $destination, $s3)) {
//                Console::error('Error uploading to ' . $destination);
//                Console::exit();
//            }
//
//            if (!$s3->exists($destination)) {
//                Console::error('File not found on s3 ' . $destination);
//                Console::exit();
//            }
//        } catch (\Exception $e) {
//            Console::error($e->getMessage());
//            Console::exit();
//        }
//
//        self::log('--- Backup End ' . (microtime(true) - $start) . ' seconds --- '   . PHP_EOL . PHP_EOL);
//
//        Console::loop(function () {
//            self::log('loop');
//        }, 100);
//    }

    public static function log(string $message): void
    {
        if (!empty($message)) {
            Console::log(date('Y-m-d H:i:s') . ' ' . $message);
        }
    }

    public function checkEnvVariables(): void
    {
        foreach (
            [
                '_APP_BACKUP_FOLDER',
                '_APP_CONNECTIONS_DB_PROJECT',
                '_DO_SPACES_BUCKET_NAME',
                '_DO_SPACES_ACCESS_KEY',
                '_DO_SPACES_SECRET_KEY',
                '_DO_SPACES_REGION'
            ] as $env
        ) {
            if (empty(App::getEnv($env))) {
                Console::error('Can\'t read ' . $env);
                Console::exit();
            }
        }
    }

    public static function startMysqlContainer($name): void
    {
        $stdout = '';
        $stderr = '';
        $cmd = 'docker start ' . $name;
        Backup::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        if (!empty($stderr)) {
            Backup::log($stdout);
            Console::error($stderr);
            Console::exit();
        }

        sleep(5); // maybe change to while?

        $cmd = 'docker ps --filter "status=running" --filter "name=' . $name . '"';
        Backup::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);

        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        $stdout = explode(PHP_EOL, $stdout);
        array_shift($stdout);
        $info = array_shift($stdout);
        if (empty($info)) {
            Console::error($name  . ' container could not start check logs!');
            Console::exit();
        }
    }

    public static function stopMysqlContainer1($name): void
    {
        $stdout = '';
        $stderr = '';
        $cmd = 'docker stop ' . $name;
        self::log($cmd);
        Console::execute($cmd, '', $stdout, $stderr);
        var_dump($stdout);
        if (!empty($stderr)) {
            self::log($stdout);
            Console::error($stderr);
            Console::exit();
        }

        sleep(5); // maybe change to while?

        $cmd = 'docker ps --filter "status=running" --filter "name=' . $name . '"';
        Console::execute($cmd, '', $stdout, $stderr);

        if (!empty($stderr)) {
            Console::error($stderr);
            Console::exit();
        }

        $stdout = explode(PHP_EOL, $stdout);
        array_shift($stdout); // remove headers row
        $info = array_shift($stdout);
        if (!empty($info)) {
            Console::error($name  . ' container could not stop check logs!');
            Console::exit();
        }
    }



    public static function stopMysqlContainer($name): void
    {

        $stdout = '';
        $stderr = '';
        Console::execute('docker exec appwrite-mariadb echo "hello"', '', $stdout, $stderr);
        var_dump($stderr);
        var_dump($stdout);


        $stdout = '';
        $stderr = '';
        Console::execute('docker exec appwrite-mariadb mysql -uroot -prootsecretpassword -e "LOCK INSTANCE FOR BACKUP;"', '', $stdout, $stderr);
        var_dump($stderr);
        var_dump($stdout);


        $stdout = '';
        $stderr = '';
        Console::execute('docker exec appwrite-mariadb mysql -uroot -prootsecretpassword -e "FLUSH TABLES WITH READ LOCK;"', '', $stdout, $stderr);
        var_dump($stderr);
        var_dump($stdout);

        Console::loop(function () {
            self::log('loop');
        }, 100);


        Console::exit();
    }
}
