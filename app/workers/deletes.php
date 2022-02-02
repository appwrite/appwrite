<?php

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Appwrite\Resque\Worker;
use Utopia\Storage\Device\Local;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Audit\Audit;

require_once __DIR__ . '/../init.php';

Console::title('Deletes V1 Worker');
Console::success(APP_NAME . ' deletes worker v1 has started' . "\n");

class DeletesV1 extends Worker
{
    /**
     * @var Database
     */
    protected $consoleDB = null;

    public function getName(): string {
        return "deletes";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        $projectId = $this->args['projectId'] ?? '';
        $type = $this->args['type'] ?? '';

        switch (strval($type)) {
            case DELETE_TYPE_DOCUMENT:
                $document = new Document($this->args['document'] ?? []);

                switch ($document->getCollection()) {
                    case DELETE_TYPE_COLLECTIONS:
                        $this->deleteCollection($document, $projectId);
                        break;
                    case DELETE_TYPE_PROJECTS:
                        $this->deleteProject($document);
                        break;
                    case DELETE_TYPE_FUNCTIONS:
                        $this->deleteFunction($document, $projectId);
                        break;
                    case DELETE_TYPE_DEPLOYMENTS:
                        $this->deleteDeployment($document, $projectId);
                        break;
                    case DELETE_TYPE_USERS:
                        $this->deleteUser($document, $projectId);
                        break;
                    case DELETE_TYPE_TEAMS:
                        $this->deleteMemberships($document, $projectId);
                        break;
                    default:
                        Console::error('No lazy delete operation available for document of type: ' . $document->getCollection());
                        break;
                }
                break;

            case DELETE_TYPE_EXECUTIONS:
                $this->deleteExecutionLogs($this->args['timestamp']);
                break;

            case DELETE_TYPE_AUDIT:
                $timestamp = $this->args['timestamp'] ?? 0;
                $document = new Document($this->args['document'] ?? []);

                if (!empty($timestamp)) {
                    $this->deleteAuditLogs($this->args['timestamp']);
                }

                if (!$document->isEmpty()) {
                    $this->deleteAuditLogsByResource('document/' . $document->getId(), $projectId);
                }

                break;

            case DELETE_TYPE_ABUSE:
                $this->deleteAbuseLogs($this->args['timestamp']);
                break;

            case DELETE_TYPE_REALTIME:
                $this->deleteRealtimeUsage($this->args['timestamp']);
                break;

            case DELETE_TYPE_CERTIFICATES:
                $document = new Document($this->args['document']);
                $this->deleteCertificates($document);
                break;

            case DELETE_TYPE_USAGE:
                $this->deleteUsageStats($this->args['timestamp1d'], $this->args['timestamp30m']);
                break;
            default:
                Console::error('No delete operation for type: ' . $type);
                break;
        }
    }

    public function shutdown(): void
    {
    }

    /**
     * @param Document $document teams document
     * @param string $projectId
     */
    protected function deleteCollection(Document $document, string $projectId): void
    {
        $collectionId = $document->getId();

        $dbForProject = $this->getProjectDB($projectId);

        $dbForProject->deleteCollection('collection_' . $collectionId);

        $this->deleteByGroup('attributes', [
            new Query('collectionId', Query::TYPE_EQUAL, [$collectionId])
        ], $dbForProject);

        $this->deleteByGroup('indexes', [
            new Query('collectionId', Query::TYPE_EQUAL, [$collectionId])
        ], $dbForProject);

        $this->deleteAuditLogsByResource('collection/' . $collectionId, $projectId);
    }

    /**
     * @param int $timestamp1d
     * @param int $timestamp30m
     */
    protected function deleteUsageStats(int $timestamp1d, int $timestamp30m)
    {
        $this->deleteForProjectIds(function (string $projectId) use ($timestamp1d, $timestamp30m) {
            $dbForProject = $this->getProjectDB($projectId);
            // Delete Usage stats
            $this->deleteByGroup('stats', [
                new Query('time', Query::TYPE_LESSER, [$timestamp1d]),
                new Query('period', Query::TYPE_EQUAL, ['1d']),
            ], $dbForProject);

            $this->deleteByGroup('stats', [
                new Query('time', Query::TYPE_LESSER, [$timestamp30m]),
                new Query('period', Query::TYPE_EQUAL, ['30m']),
            ], $dbForProject);
        });
    }

    /**
     * @param Document $document teams document
     * @param string $projectId
     */
    protected function deleteMemberships(Document $document, string $projectId): void
    {
        $teamId = $document->getAttribute('teamId', '');

        // Delete Memberships
        $this->deleteByGroup('memberships', [
            new Query('teamId', Query::TYPE_EQUAL, [$teamId])
        ], $this->getProjectDB($projectId));
    }

    /**
     * @param Document $document project document
     */
    protected function deleteProject(Document $document): void
    {
        $projectId = $document->getId();

        // Delete all DBs
        $this->getProjectDB($projectId)->delete($projectId);

        // Delete all storage directories
        $uploads = new Local(APP_STORAGE_UPLOADS . '/app-' . $document->getId());
        $cache = new Local(APP_STORAGE_CACHE . '/app-' . $document->getId());

        $uploads->delete($uploads->getRoot(), true);
        $cache->delete($cache->getRoot(), true);
    }

    /**
     * @param Document $document user document
     * @param string $projectId
     */
    protected function deleteUser(Document $document, string $projectId): void
    {
        /**
         * DO NOT DELETE THE USER RECORD ITSELF. 
         * WE RETAIN THE USER RECORD TO RESERVE THE USER ID AND ENSURE THAT THE USER ID IS NOT REUSED.
         */
        
        $userId = $document->getId();
        $user = $this->getProjectDB($projectId)->getDocument('users', $userId);

        // Delete all sessions of this user from the sessions table and update the sessions field of the user record
        $this->deleteByGroup('sessions', [
            new Query('userId', Query::TYPE_EQUAL, [$userId])
        ], $this->getProjectDB($projectId));
        
        $user->setAttribute('sessions', []);
        $updated = Authorization::skip(fn() => $this->getProjectDB($projectId)->updateDocument('users', $userId, $user));

        // Delete Memberships and decrement team membership counts
        $this->deleteByGroup('memberships', [
            new Query('userId', Query::TYPE_EQUAL, [$userId])
        ], $this->getProjectDB($projectId), function (Document $document) use ($projectId) {

            if ($document->getAttribute('confirm')) { // Count only confirmed members
                $teamId = $document->getAttribute('teamId');
                $team = $this->getProjectDB($projectId)->getDocument('teams', $teamId);
                if (!$team->isEmpty()) {
                    $team = $this->getProjectDB($projectId)->updateDocument('teams', $teamId, new Document(\array_merge($team->getArrayCopy(), [
                        'sum' => \max($team->getAttribute('sum', 0) - 1, 0), // Ensure that sum >= 0
                    ])));
                }
            }
        });
    }

    /**
     * @param int $timestamp
     */
    protected function deleteExecutionLogs(int $timestamp): void
    {
        $this->deleteForProjectIds(function (string $projectId) use ($timestamp) {
            $dbForProject = $this->getProjectDB($projectId);
            // Delete Executions
            $this->deleteByGroup('executions', [
                new Query('dateCreated', Query::TYPE_LESSER, [$timestamp])
            ], $dbForProject);
        });
    }

    /**
     * @param int $timestamp
     */
    protected function deleteRealtimeUsage(int $timestamp): void
    {
        $this->deleteForProjectIds(function (string $projectId) use ($timestamp) {
            $dbForProject = $this->getProjectDB($projectId);
            // Delete Dead Realtime Logs
            $this->deleteByGroup('realtime', [
                new Query('timestamp', Query::TYPE_LESSER, [$timestamp])
            ], $dbForProject);
        });
    }

    /**
     * @param int $timestamp
     */
    protected function deleteAbuseLogs(int $timestamp): void
    {
        if ($timestamp == 0) {
            throw new Exception('Failed to delete audit logs. No timestamp provided');
        }

        $this->deleteForProjectIds(function (string $projectId) use ($timestamp) {
            $dbForProject = $this->getProjectDB($projectId);
            $timeLimit = new TimeLimit("", 0, 1, $dbForProject);
            $abuse = new Abuse($timeLimit);

            $status = $abuse->cleanup($timestamp);
            if (!$status) {
                throw new Exception('Failed to delete Abuse logs for project ' . $projectId);
            }
        });
    }

    /**
     * @param int $timestamp
     */
    protected function deleteAuditLogs(int $timestamp): void
    {
        if ($timestamp == 0) {
            throw new Exception('Failed to delete audit logs. No timestamp provided');
        }
        $this->deleteForProjectIds(function (string $projectId) use ($timestamp) {
            $dbForProject = $this->getProjectDB($projectId);
            $audit = new Audit($dbForProject);
            $status = $audit->cleanup($timestamp);
            if (!$status) {
                throw new Exception('Failed to delete Audit logs for project' . $projectId);
            }
        });
    }

    /**
     * @param int $timestamp
     */
    protected function deleteAuditLogsByResource(string $resource, string $projectId): void
    {
        $dbForProject = $this->getProjectDB($projectId);

        $this->deleteByGroup(Audit::COLLECTION, [
            new Query('resource', Query::TYPE_EQUAL, [$resource])
        ], $dbForProject);
    }

    /**
     * @param Document $document function document
     * @param string $projectId
     */
    protected function deleteFunction(Document $document, string $projectId): void
    {
        $dbForProject = $this->getProjectDB($projectId);

        /**
         * Delete Deployments
         */
        $storageFunctions = new Local(APP_STORAGE_FUNCTIONS . '/app-' . $projectId);
        $deploymentIds = [];
        $this->deleteByGroup('deployments', [
            new Query('resourceId', Query::TYPE_EQUAL, [$document->getId()])
        ], $dbForProject, function (Document $document) use ($storageFunctions, &$deploymentIds) {
            $deploymentIds[] = $document->getId();
            if ($storageFunctions->delete($document->getAttribute('path', ''), true)) {
                Console::success('Deleted deployment files: ' . $document->getAttribute('path', ''));
            } else {
                Console::error('Failed to delete deployment files: ' . $document->getAttribute('path', ''));
            }
        });

        /**
         * Delete builds
         */
        $storageBuilds = new Local(APP_STORAGE_BUILDS . '/app-' . $projectId);
        $buildIds = [];
         foreach ($deploymentIds as $deploymentId) {
            $this->deleteByGroup('builds', [
                new Query('deploymentId', Query::TYPE_EQUAL, [$deploymentId])
            ], $dbForProject, function (Document $document) use ($storageBuilds, $deploymentId, &$buildIds) {
                $buildIds[$deploymentId][] = $document->getId();
                if ($storageBuilds->delete($document->getAttribute('outputPath', ''), true)) {
                    Console::success('Deleted build files: ' . $document->getAttribute('outputPath', ''));
                } else {
                    Console::error('Failed to delete build files: ' . $document->getAttribute('outputPath', ''));
                }
            });
        }

        // Delete Executions
        $this->deleteByGroup('executions', [
            new Query('functionId', Query::TYPE_EQUAL, [$document->getId()])
        ], $dbForProject);

        /**
         * Request executor to delete all deployment containers
         */
        foreach ($deploymentIds as $deploymentId) {
            try {
                $route = "/deployments/$deploymentId";
                $headers = [
                    'content-Type' =>  'application/json',
                    'x-appwrite-project' => $projectId,
                    'x-appwrite-executor-key' => App::getEnv('_APP_EXECUTOR_SECRET', '')
                ];
                $params = [
                    'buildIds' => $buildIds[$deploymentId] ?? [],
                ];
                $response = $this->call(self::METHOD_DELETE, $route, $headers, $params, true, 30);
                $status = $response['headers']['status-code'];
                if ($status >= 400) {
                    throw new \Exception('Error deleting deplyoment: ' . $document->getId() , $status);
                } 
            } catch (Throwable $th) {
                Console::error($th->getMessage());
            }
        }

    }

    /**
     * @param Document $document deployment document
     * @param string $projectId
     */
    protected function deleteDeployment(Document $document, string $projectId): void
    {
        $dbForProject = $this->getProjectDB($projectId);

        /**
         * Delete deployment files
         */
        $storageFunctions = new Local(APP_STORAGE_FUNCTIONS . '/app-' . $projectId);
        if ($storageFunctions->delete($document->getAttribute('path', ''), true)) {
            Console::success('Deleted deployment files: ' . $document->getAttribute('path', ''));
        } else {
            Console::error('Failed to delete deployment files: ' . $document->getAttribute('path', ''));
        }

        /**
         * Delete builds
         */
        $buildIds = [];
        $storageBuilds = new Local(APP_STORAGE_BUILDS . '/app-' . $projectId);
        $this->deleteByGroup('builds', [
            new Query('deploymentId', Query::TYPE_EQUAL, [$document->getId()])
        ], $dbForProject, function (Document $document) use ($storageBuilds) {
            $buildIds[] = $document->getId();
            if ($storageBuilds->delete($document->getAttribute('outputPath', ''), true)) {
                Console::success('Deleted build files: ' . $document->getAttribute('outputPath', ''));
            } else {
                Console::error('Failed to delete build files: ' . $document->getAttribute('outputPath', ''));
            }
        });

        /**
         * Request executor to delete the deployment container
         */
        try {
            $route = "/deployments/{$document->getId()}";
            $headers = [
                'content-Type' =>  'application/json',
                'x-appwrite-project' => $projectId,
                'x-appwrite-executor-key' => App::getEnv('_APP_EXECUTOR_SECRET', '')
            ];
            $params = [
                'buildIds' => $buildIds ?? []
            ];

            $response = $this->call(self::METHOD_DELETE, $route, $headers, $params, true, 30);
            $status = $response['headers']['status-code'];
            if ($status >= 400) {
                throw new \Exception('Error deleting deplyoment: ' . $document->getId() , $status);
            } 
        } catch (Throwable $th) {
            Console::error($th->getMessage());
        }
    }


    /**
     * @param Document $document to be deleted
     * @param Database $database to delete it from
     * @param callable $callback to perform after document is deleted
     *
     * @return bool
     */
    protected function deleteById(Document $document, Database $database, callable $callback = null): bool
    {
        Authorization::disable();

        if ($database->deleteDocument($document->getCollection(), $document->getId())) {
            Console::success('Deleted document "' . $document->getId() . '" successfully');

            if (is_callable($callback)) {
                $callback($document);
            }

            return true;
        } else {
            Console::error('Failed to delete document: ' . $document->getId());
            return false;
        }

        Authorization::reset();
    }

    /**
     * @param callable $callback
     */
    protected function deleteForProjectIds(callable $callback): void
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $projects = [];
        $sum = $limit;

        $executionStart = \microtime(true);

        while ($sum === $limit) {
            $projects = Authorization::skip(fn() => $this->getConsoleDB()->find('projects', [], $limit, ($chunk * $limit)));

            $chunk++;

            /** @var string[] $projectIds */
            $projectIds = array_map(fn(Document $project) => $project->getId(), $projects);

            $sum = count($projects);

            Console::info('Executing delete function for chunk #' . $chunk . '. Found ' . $sum . ' projects');
            foreach ($projectIds as $projectId) {
                $callback($projectId);
                $count++;
            }
        }

        $executionEnd = \microtime(true);
        Console::info("Found {$count} projects " . ($executionEnd - $executionStart) . " seconds");
    }

    /**
     * @param string $collection collectionID
     * @param Query[] $queries
     * @param Database $database
     * @param callable $callback
     */
    protected function deleteByGroup(string $collection, array $queries, Database $database, callable $callback = null): void
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $results = [];
        $sum = $limit;

        $executionStart = \microtime(true);

        while ($sum === $limit) {
            $chunk++;

            Authorization::disable();

            $results = $database->find($collection, $queries, $limit, 0);

            Authorization::reset();

            $sum = count($results);

            Console::info('Deleting chunk #' . $chunk . '. Found ' . $sum . ' documents');

            foreach ($results as $document) {
                $this->deleteById($document, $database, $callback);
                $count++;
            }
        }

        $executionEnd = \microtime(true);

        Console::info("Deleted {$count} document by group in " . ($executionEnd - $executionStart) . " seconds");
    }

    /**
     * @param Document $document certificates document 
     */
    protected function deleteCertificates(Document $document): void
    {
        $domain = $document->getAttribute('domain');
        $directory = APP_STORAGE_CERTIFICATES . '/' . $domain;
        $checkTraversal = realpath($directory) === $directory;

        if ($domain && $checkTraversal && is_dir($directory)) {
            array_map('unlink', glob($directory . '/*.*'));
            rmdir($directory);
            Console::info("Deleted certificate files for {$domain}");
        } else {
            Console::info("No certificate files found for {$domain}");
        }
    }

    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_PATCH = 'PATCH';
    const METHOD_DELETE = 'DELETE';
    const METHOD_HEAD = 'HEAD';
    const METHOD_OPTIONS = 'OPTIONS';
    const METHOD_CONNECT = 'CONNECT';
    const METHOD_TRACE = 'TRACE';

    protected $selfSigned = false;
    private $endpoint = 'http://appwrite-executor/v1';
    protected $headers = [
        'content-type' => '',
    ];

    /**
     * Call
     *
     * Make an API call
     *
     * @param string $method
     * @param string $path
     * @param array $params
     * @param array $headers
     * @param bool $decode
     * @return array|string
     * @throws Exception
     */
    public function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, int $timeout = 15)
    {
        $headers            = array_merge($this->headers, $headers);
        $ch                 = curl_init($this->endpoint . $path . (($method == self::METHOD_GET && !empty($params)) ? '?' . http_build_query($params) : ''));
        $responseHeaders    = [];
        $responseStatus     = -1;
        $responseType       = '';
        $responseBody       = '';

        switch ($headers['content-type']) {
            case 'application/json':
                $query = json_encode($params);
                break;

            case 'multipart/form-data':
                $query = $this->flatten($params);
                break;

            default:
                $query = http_build_query($params);
                break;
        }

        foreach ($headers as $i => $header) {
            $headers[] = $i . ':' . $header;
            unset($headers[$i]);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.77 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0); 
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);

            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });

        if ($method != self::METHOD_GET) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }

        // Allow self signed certificates
        if ($this->selfSigned) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $responseBody   = curl_exec($ch);
        $responseType   = $responseHeaders['content-type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if($decode) {
            switch (substr($responseType, 0, strpos($responseType, ';'))) {
                case 'application/json':
                    $json = json_decode($responseBody, true);
    
                    if ($json === null) {
                        throw new Exception('Failed to parse response: '.$responseBody);
                    }
    
                    $responseBody = $json;
                    $json = null;
                break;
            }
        }

        if ((curl_errno($ch)/* || 200 != $responseStatus*/)) {
            throw new Exception(curl_error($ch) . ' with status code ' . $responseStatus, $responseStatus);
        }

        curl_close($ch);

        $responseHeaders['status-code'] = $responseStatus;

        if ($responseStatus === 500) {
            echo 'Server error('.$method.': '.$path.'. Params: '.json_encode($params).'): '.json_encode($responseBody)."\n";
        }

        return [
            'headers' => $responseHeaders,
            'body' => $responseBody
        ];
    }

    /**
     * Parse Cookie String
     *
     * @param string $cookie
     * @return array
     */
    public function parseCookie(string $cookie): array
    {
        $cookies = [];

        parse_str(strtr($cookie, array('&' => '%26', '+' => '%2B', ';' => '&')), $cookies);

        return $cookies;
    }

    /**
     * Flatten params array to PHP multiple format
     *
     * @param array $data
     * @param string $prefix
     * @return array
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $output = [];

        foreach ($data as $key => $value) {
            $finalKey = $prefix ? "{$prefix}[{$key}]" : $key;

            if (is_array($value)) {
                $output += $this->flatten($value, $finalKey); // @todo: handle name collision here if needed
            } else {
                $output[$finalKey] = $value;
            }
        }

        return $output;
    }
}
