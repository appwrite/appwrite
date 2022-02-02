<?php

use Appwrite\Resque\Worker;
use Cron\CronExpression;
use Utopia\Database\Validator\Authorization;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Storage\Storage;
use Utopia\Database\Document;
use Utopia\Config\Config;

require_once __DIR__.'/../init.php';

// Disable Auth since we already validate it in the API
Authorization::disable();

Console::title('Builds V1 Worker');
Console::success(APP_NAME.' build worker v1 has started');

// TODO: Executor should return appropriate response codes.
class BuildsV1 extends Worker
{ 
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

    public function getName(): string 
    {
        return "builds";
    }

    public function init(): void {}

    public function run(): void
    {
        $type = $this->args['type'] ?? '';
        $projectId = $this->args['projectId'] ?? '';

        switch ($type) {
            case BUILD_TYPE_DEPLOYMENT:
                $functionId = $this->args['functionId'] ?? '';
                $deploymentId = $this->args['deploymentId'] ?? '';
                Console::info("Creating build for deployment: $deploymentId");
                $this->buildDeployment($projectId, $functionId, $deploymentId);
                break;

            // case BUILD_TYPE_RETRY:
            //     $buildId = $this->args['buildId'] ?? '';
            //     $functionId = $this->args['functionId'] ?? '';
            //     $deploymentId = $this->args['deploymentId'] ?? '';
            //     Console::info("Retrying build for id: $buildId");
            //     $this->createBuild($projectId, $functionId, $deploymentId, $buildId);
            //     break;

            default:
                throw new \Exception('Invalid build type');
                break;
        }
    }


    protected function createBuild(string $projectId, string $functionId, string $deploymentId, string $buildId, string $path, array $vars, string $runtime, string $baseImage)
    {
        $route = "/functions/$functionId/deployments/$deploymentId/builds/$buildId";
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-executor-key' => App::getEnv('_APP_EXECUTOR_SECRET', '')
        ];
        $params = [
            'path' => $path,
            'vars' => $vars,
            'runtime' => $runtime,
            'baseImage' => $baseImage
        ];

        $response = $this->call(self::METHOD_POST, $route, $headers, $params, true, 30);

        $status = $response['headers']['status-code'];
        if ($status >= 400) {
            throw new \Exception('Error creating build: ', $status);
        } 

        return $response['body'];
    }

    protected function buildDeployment(string $projectId, string $functionId, string $deploymentId) 
    {
        $dbForProject = $this->getProjectDB($projectId);
        
        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404);
        }

        $runtimes = Config::getParam('runtimes', []);
        $key = $function->getAttribute('runtime');
        $runtime = isset($runtimes[$key]) ? $runtimes[$key] : null;
        if (\is_null($runtime)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        $buildId = $deployment->getAttribute('buildId', '');
        $build = null;
        if (empty($buildId)) {
            $buildId = $dbForProject->getId();
            $build = $dbForProject->createDocument('builds', new Document([
                '$id' => $buildId,
                '$read' => [],
                '$write' => [],
                'startTime' => time(),
                'deploymentId' => $deploymentId,
                'status' => 'processing',
                'outputPath' => '',
                'runtime' => $function->getAttribute('runtime'),
                'source' => $deployment->getAttribute('path'),
                'sourceType' => Storage::DEVICE_LOCAL,
                'stdout' => '',
                'stderr' => '',
                'endTime' => 0,
                'duration' => 0
            ]));
            $deployment->setAttribute('buildId', $buildId);
            $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment);
        } else {
            $build = $dbForProject->getDocument('builds', $buildId);
        }

        /** Request the executor to build the code... */
        $build->setAttribute('status', 'building');
        $build = $dbForProject->updateDocument('builds', $buildId, $build);

        $path = $deployment->getAttribute('path');
        $vars = $function->getAttribute('vars', []);
        $baseImage = $runtime['image'];
        $response = $this->createBuild($projectId, $functionId, $deploymentId, $buildId, $path, $vars, $key, $baseImage);
        
        /** Update the build document */
        $build->setAttribute('endTime', $response['endTime']);
        $build->setAttribute('duration', $response['duration']);
        $build->setAttribute('status', $response['status']);
        $build->setAttribute('outputPath', $response['outputPath']);
        $build->setAttribute('stderr', $response['stderr']);
        $build->setAttribute('stdout', $response['stdout']);
        $build = $dbForProject->updateDocument('builds', $buildId, $build);

        /** Set auto deploy */
        if ($deployment->getAttribute('deploy') === true) {
            $function->setAttribute('deployment', $deployment->getId());
            $function = $dbForProject->updateDocument('functions', $functionId, $function);
        }

        /** Update function schedule */
        $schedule = $function->getAttribute('schedule', '');
        $cron = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : 0;
        $function->setAttribute('scheduleNext', (int)$next);
        $function = $dbForProject->updateDocument('functions', $functionId, $function);

        // /** Create runtime server */


        Console::success("Build id: $buildId created");
    }

    public function shutdown(): void {}

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
