<?php

namespace Utopia\Migration\Sources;

use Utopia\Migration\Exception;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Auth\Hash;
use Utopia\Migration\Resources\Auth\User;
use Utopia\Migration\Resources\Storage\Bucket;
use Utopia\Migration\Resources\Storage\File;
use Utopia\Migration\Transfer;

const MIME_MAP = [
    'video/3gpp2' => '3g2',
    'video/3gp' => '3gp',
    'video/3gpp' => '3gp',
    'application/x-compressed' => '7zip',
    'audio/x-acc' => 'aac',
    'audio/ac3' => 'ac3',
    'application/postscript' => 'ai',
    'audio/x-aiff' => 'aif',
    'audio/aiff' => 'aif',
    'audio/x-au' => 'au',
    'video/x-msvideo' => 'avi',
    'video/msvideo' => 'avi',
    'video/avi' => 'avi',
    'application/x-troff-msvideo' => 'avi',
    'application/macbinary' => 'bin',
    'application/mac-binary' => 'bin',
    'application/x-binary' => 'bin',
    'application/x-macbinary' => 'bin',
    'image/bmp' => 'bmp',
    'image/x-bmp' => 'bmp',
    'image/x-bitmap' => 'bmp',
    'image/x-xbitmap' => 'bmp',
    'image/x-win-bitmap' => 'bmp',
    'image/x-windows-bmp' => 'bmp',
    'image/ms-bmp' => 'bmp',
    'image/x-ms-bmp' => 'bmp',
    'application/bmp' => 'bmp',
    'application/x-bmp' => 'bmp',
    'application/x-win-bitmap' => 'bmp',
    'application/cdr' => 'cdr',
    'application/coreldraw' => 'cdr',
    'application/x-cdr' => 'cdr',
    'application/x-coreldraw' => 'cdr',
    'image/cdr' => 'cdr',
    'image/x-cdr' => 'cdr',
    'zz-application/zz-winassoc-cdr' => 'cdr',
    'application/mac-compactpro' => 'cpt',
    'application/pkix-crl' => 'crl',
    'application/pkcs-crl' => 'crl',
    'application/x-x509-ca-cert' => 'crt',
    'application/pkix-cert' => 'crt',
    'text/css' => 'css',
    'text/x-comma-separated-values' => 'csv',
    'text/comma-separated-values' => 'csv',
    'application/vnd.msexcel' => 'csv',
    'application/x-director' => 'dcr',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/x-dvi' => 'dvi',
    'message/rfc822' => 'eml',
    'application/x-msdownload' => 'exe',
    'video/x-f4v' => 'f4v',
    'audio/x-flac' => 'flac',
    'video/x-flv' => 'flv',
    'image/gif' => 'gif',
    'application/gpg-keys' => 'gpg',
    'application/x-gtar' => 'gtar',
    'application/x-gzip' => 'gzip',
    'application/mac-binhex40' => 'hqx',
    'application/mac-binhex' => 'hqx',
    'application/x-binhex40' => 'hqx',
    'application/x-mac-binhex40' => 'hqx',
    'text/html' => 'html',
    'image/x-icon' => 'ico',
    'image/x-ico' => 'ico',
    'image/vnd.microsoft.icon' => 'ico',
    'text/calendar' => 'ics',
    'application/java-archive' => 'jar',
    'application/x-java-application' => 'jar',
    'application/x-jar' => 'jar',
    'image/jp2' => 'jp2',
    'video/mj2' => 'jp2',
    'image/jpx' => 'jp2',
    'image/jpm' => 'jp2',
    'image/jpeg' => 'jpeg',
    'image/jpg' => 'jpeg',
    'image/pjpeg' => 'jpeg',
    'application/x-javascript' => 'js',
    'application/json' => 'json',
    'text/json' => 'json',
    'application/vnd.google-earth.kml+xml' => 'kml',
    'application/vnd.google-earth.kmz' => 'kmz',
    'text/x-log' => 'log',
    'audio/x-m4a' => 'm4a',
    'audio/mp4' => 'm4a',
    'application/vnd.mpegurl' => 'm4u',
    'audio/midi' => 'mid',
    'application/vnd.mif' => 'mif',
    'video/quicktime' => 'mov',
    'video/x-sgi-movie' => 'movie',
    'audio/mpeg' => 'mp3',
    'audio/mpg' => 'mp3',
    'audio/mpeg3' => 'mp3',
    'audio/mp3' => 'mp3',
    'video/mp4' => 'mp4',
    'video/mpeg' => 'mpeg',
    'application/oda' => 'oda',
    'audio/ogg' => 'ogg',
    'video/ogg' => 'ogg',
    'application/ogg' => 'ogg',
    'font/otf' => 'otf',
    'application/x-pkcs10' => 'p10',
    'application/pkcs10' => 'p10',
    'application/x-pkcs12' => 'p12',
    'application/x-pkcs7-signature' => 'p7a',
    'application/pkcs7-mime' => 'p7c',
    'application/x-pkcs7-mime' => 'p7c',
    'application/x-pkcs7-certreqresp' => 'p7r',
    'application/pkcs7-signature' => 'p7s',
    'application/pdf' => 'pdf',
    'application/octet-stream' => 'pdf',
    'application/x-x509-user-cert' => 'pem',
    'application/x-pem-file' => 'pem',
    'application/pgp' => 'pgp',
    'application/x-httpd-php' => 'php',
    'application/php' => 'php',
    'application/x-php' => 'php',
    'text/php' => 'php',
    'text/x-php' => 'php',
    'application/x-httpd-php-source' => 'php',
    'image/png' => 'png',
    'image/x-png' => 'png',
    'application/powerpoint' => 'ppt',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.ms-office' => 'ppt',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/x-photoshop' => 'psd',
    'image/vnd.adobe.photoshop' => 'psd',
    'audio/x-realaudio' => 'ra',
    'audio/x-pn-realaudio' => 'ram',
    'application/x-rar' => 'rar',
    'application/rar' => 'rar',
    'application/x-rar-compressed' => 'rar',
    'audio/x-pn-realaudio-plugin' => 'rpm',
    'application/x-pkcs7' => 'rsa',
    'text/rtf' => 'rtf',
    'text/richtext' => 'rtx',
    'video/vnd.rn-realvideo' => 'rv',
    'application/x-stuffit' => 'sit',
    'application/smil' => 'smil',
    'text/srt' => 'srt',
    'image/svg+xml' => 'svg',
    'application/x-shockwave-flash' => 'swf',
    'application/x-tar' => 'tar',
    'application/x-gzip-compressed' => 'tgz',
    'image/tiff' => 'tiff',
    'font/ttf' => 'ttf',
    'text/plain' => 'txt',
    'text/x-vcard' => 'vcf',
    'application/videolan' => 'vlc',
    'text/vtt' => 'vtt',
    'audio/x-wav' => 'wav',
    'audio/wave' => 'wav',
    'audio/wav' => 'wav',
    'application/wbxml' => 'wbxml',
    'video/webm' => 'webm',
    'image/webp' => 'webp',
    'audio/x-ms-wma' => 'wma',
    'application/wmlc' => 'wmlc',
    'video/x-ms-wmv' => 'wmv',
    'video/x-ms-asf' => 'wmv',
    'font/woff' => 'woff',
    'font/woff2' => 'woff2',
    'application/xhtml+xml' => 'xhtml',
    'application/excel' => 'xl',
    'application/msexcel' => 'xls',
    'application/x-msexcel' => 'xls',
    'application/x-ms-excel' => 'xls',
    'application/x-excel' => 'xls',
    'application/x-dos_ms_excel' => 'xls',
    'application/xls' => 'xls',
    'application/x-xls' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-excel' => 'xlsx',
    'application/xml' => 'xml',
    'text/xml' => 'xml',
    'text/xsl' => 'xsl',
    'application/xspf+xml' => 'xspf',
    'application/x-compress' => 'z',
    'application/x-zip' => 'zip',
    'application/zip' => 'zip',
    'application/x-zip-compressed' => 'zip',
    'application/s-compressed' => 'zip',
    'multipart/x-zip' => 'zip',
    'text/x-scriptzsh' => 'zsh',
];

class Supabase extends NHost
{
    public static function getName(): string
    {
        return 'Supabase';
    }

    protected string $key;

    protected string $host;

    public function __construct(string $endpoint, string $key, string $host, string $databaseName, string $username, string $password, string $port = '5432')
    {
        $this->endpoint = $endpoint;
        $this->key = $key;
        $this->host = $host;
        $this->databaseName = $databaseName;
        $this->username = $username;
        $this->password = $password;
        $this->port = $port;

        $this->headers['Authorization'] = 'Bearer '.$this->key;
        $this->headers['apiKey'] = $this->key;

        try {
            $this->pdo = new \PDO('pgsql:host='.$this->host.';port='.$this->port.';dbname='.$this->databaseName, $this->username, $this->password);
        } catch (\PDOException $e) {
            throw new \Exception('Failed to connect to database: '.$e->getMessage(), parent::statusCodeFromThrowable($e), $e);
        }
    }

    public function report(array $resources = [], array $resourceIds = []): array
    {
        $report = [];

        if (empty($resources)) {
            $resources = $this->getSupportedResources();
        }

        try {
            $this->pdo = new \PDO('pgsql:host='.$this->host.';port='.$this->port.';dbname='.$this->databaseName, $this->username, $this->password);
        } catch (\PDOException $e) {
            throw new \Exception('Failed to connect to database. PDO Code: '.$e->getCode().' Error: '.$e->getMessage(), parent::statusCodeFromThrowable($e), $e);
        }

        if (! empty($this->pdo->errorCode())) {
            throw new \Exception(
                'Failed to connect to database. PDO Code: '.$this->pdo->errorCode().(empty($this->pdo->errorInfo()[2]) ? '' : ' Error: '.$this->pdo->errorInfo()[2]),
                parent::statusCodeFromSqlState($this->pdo->errorCode())
            );
        }

        // Auth
        if (\in_array(Resource::TYPE_USER, $resources)) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM auth.users');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access users table. Error: '.$statement->errorInfo()[2],
                    parent::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_USER] = $statement->fetchColumn();
        }

        // Databases
        if (\in_array(Resource::TYPE_DATABASE, $resources)) {
            $report[Resource::TYPE_DATABASE] = 1;
        }

        if (Resource::isSupported(Resource::TYPE_TABLE, $resources)) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \'public\'');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access tables table. Error: '.$statement->errorInfo()[2],
                    parent::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_TABLE] = $statement->fetchColumn();
        }

        if (Resource::isSupported(Resource::TYPE_COLUMN, $resources)) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = \'public\'');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access columns table. Error: '.$statement->errorInfo()[2],
                    parent::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_COLUMN] = $statement->fetchColumn();
        }

        if (\in_array(Resource::TYPE_INDEX, $resources)) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM pg_indexes WHERE schemaname = \'public\'');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access indexes table. Error: '.$statement->errorInfo()[2],
                    parent::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_INDEX] = $statement->fetchColumn();
        }

        if (Resource::isSupported(Resource::TYPE_ROW, $resources)) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \'public\'');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access tables table. Error: '.$statement->errorInfo()[2],
                    parent::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_ROW] = $statement->fetchColumn();
        }

        // Storage
        if (\in_array(Resource::TYPE_BUCKET, $resources)) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM storage.buckets');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access buckets table. Error: '.$statement->errorInfo()[2],
                    parent::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_BUCKET] = $statement->fetchColumn();
        }

        if (\in_array(Resource::TYPE_FILE, $resources)) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM storage.objects');
            $statement->execute();

            if ($statement->errorCode() !== '00000') {
                throw new \Exception(
                    'Failed to access files table. Error: '.$statement->errorInfo()[2],
                    parent::statusCodeFromErrorInfo($statement->errorInfo())
                );
            }

            $report[Resource::TYPE_FILE] = $statement->fetchColumn();

            $statementFileSize = $this->pdo->prepare('SELECT objects.metadata FROM storage.objects;');
            $statementFileSize->execute();

            $report['size'] = 0;
            foreach ($statementFileSize->fetchAll(\PDO::FETCH_ASSOC) as $file) {
                $metadata = json_decode($file['metadata'], true);

                $report['size'] += ($metadata['size'] / 1024 / 1024); // MB
            }
        }

        $this->previousReport = $report;

        return $report;
    }

    protected function exportGroupAuth(int $batchSize, array $resources): void
    {
        try {
            if (\in_array(Resource::TYPE_USER, $resources)) {
                $this->exportUsers($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_BUCKET,
                Transfer::GROUP_STORAGE,
                message: $e->getMessage(),
                code: parent::statusCodeFromThrowable($e),
                previous: $e
            ));
        }
    }

    private function exportUsers(int $batchSize): void
    {
        $total = $this->pdo->query('SELECT COUNT(*) FROM auth.users')->fetchColumn();

        $offset = 0;

        while ($offset < $total) {
            $statement = $this->pdo->prepare('SELECT * FROM auth.users order by created_at LIMIT :limit OFFSET :offset');
            $statement->bindValue(':limit', $batchSize, \PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $statement->execute();

            $users = $statement->fetchAll(\PDO::FETCH_ASSOC);

            $offset += $batchSize;

            $transferUsers = [];

            foreach ($users as $user) {
                $hash = null;

                if (array_key_exists('encrypted_password', $user) && ! empty($user['encrypted_password'])) {
                    $hash = new Hash($user['encrypted_password'], '', Hash::ALGORITHM_BCRYPT);
                }

                $transferUser = new User(
                    $user['id'],
                    $user['email'] ?? null,
                    '',
                    $hash,
                    $user['phone'] ?? null,
                    [],
                    '',
                    ! empty($user['email_confirmed_at']),
                    ! empty($user['phone_confirmed_at']),
                    false,
                    []
                );

                $transferUsers[] = $transferUser;
            }

            $this->callback($transferUsers);
        }
    }

    private function convertMimes(array $mimes): array
    {
        $extensions = [];

        foreach ($mimes as $mime) {
            $extensions[] = MIME_MAP[$mime] ?? '';
        }

        return $extensions;
    }

    protected function exportGroupStorage(int $batchSize, array $resources): void
    {
        try {
            if (\in_array(Resource::TYPE_BUCKET, $resources)) {
                $this->exportBuckets($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_BUCKET,
                Transfer::GROUP_STORAGE,
                message: $e->getMessage(),
                code: parent::statusCodeFromThrowable($e),
                previous: $e
            ));
        }

        try {
            if (\in_array(Resource::TYPE_FILE, $resources)) {
                $this->exportFiles($batchSize);
            }
        } catch (\Throwable $e) {
            $this->addError(new Exception(
                Resource::TYPE_BUCKET,
                Transfer::GROUP_STORAGE,
                message: $e->getMessage(),
                code: parent::statusCodeFromThrowable($e),
                previous: $e
            ));
        }
    }

    protected function exportBuckets(int $batchSize): void
    {
        $statement = $this->pdo->prepare('SELECT * FROM storage.buckets order by created_at');
        $statement->execute();

        $buckets = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $transferBuckets = [];

        foreach ($buckets as $bucket) {
            $convertedBucket = new Bucket(
                'unique()',
                $bucket['name'],
                [],
                false,
                true,
                $bucket['file_size_limit'] ?? null,
                $bucket['allowed_mime_types'] ? $this->convertMimes($bucket['allowed_mime_types']) : [],
            );
            $convertedBucket->setOriginalId($bucket['id']);
            $transferBuckets[] = $convertedBucket;
        }

        $this->callback($transferBuckets);
    }

    public function exportFiles(int $batchSize): void
    {
        /**
         * TODO: Supabase has folders, with enough folders within folders this could cause us to hit the max name length
         * Need to figure out a solution to this.
         */
        $buckets = $this->cache->get(Bucket::getName());

        foreach ($buckets as $bucket) {
            /** @var Bucket $bucket */
            $totalStatement = $this->pdo->prepare('SELECT COUNT(*) FROM storage.objects WHERE bucket_id=:bucketId');
            $totalStatement->execute([':bucketId' => $bucket->getOriginalId()]);
            $total = $totalStatement->fetchColumn();

            $offset = 0;
            while ($offset < $total) {
                $statement = $this->pdo->prepare('SELECT * FROM storage.objects WHERE bucket_id=:bucketId ORDER BY created_at LIMIT :limit OFFSET :offset');
                $statement->execute([
                    ':bucketId' => $bucket->getOriginalId(),
                    ':limit' => $batchSize,
                    ':offset' => $offset,
                ]);

                $files = $statement->fetchAll(\PDO::FETCH_ASSOC);

                $offset += $batchSize;

                foreach ($files as $file) {
                    $metadata = json_decode($file['metadata'], true);

                    $this->exportFile(new File(
                        $file['id'],
                        $bucket,
                        $file['name'],
                        '',
                        $metadata['mimetype'],
                        [],
                        $metadata['size']
                    ));
                }
            }
        }
    }

    public function exportFile(File $file): void
    {
        $start = 0;
        $end = Transfer::STORAGE_MAX_CHUNK_SIZE - 1;

        $fileSize = $file->getSize();

        if ($end > $fileSize) {
            $end = $fileSize - 1;
        }

        // Loop until the entire file is downloaded
        while ($start < $fileSize) {
            $chunkData = $this->call(
                'GET',
                '/storage/v1/object/'.
                    rawurlencode($file->getBucket()->getOriginalId()).'/'.rawurlencode($file->getFileName()),
                ['range' => "bytes=$start-$end"]
            );

            // Send the chunk to the callback function
            $file->setData($chunkData)
                ->setStart($start)
                ->setEnd($end);

            $this->callback([$file]);

            // Update the range
            $start += Transfer::STORAGE_MAX_CHUNK_SIZE;
            $end += Transfer::STORAGE_MAX_CHUNK_SIZE;

            if ($end > $fileSize) {
                $end = $fileSize - 1;
            }
        }
    }
}
