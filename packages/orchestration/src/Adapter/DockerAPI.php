<?php

namespace Utopia\Orchestration\Adapter;

use stdClass;
use Utopia\Orchestration\Adapter;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Container\Stats;
use Utopia\Orchestration\Exception\Orchestration;
use Utopia\Orchestration\Exception\Timeout;
use Utopia\Orchestration\Network;

class DockerAPI extends Adapter
{
    /**
     * Constructor
     */
    public function __construct(?string $username = null, ?string $password = null, ?string $email = null)
    {
        if ($username && $password && $email) {
            $this->registryAuth = base64_encode(json_encode([
                'username' => $username,
                'password' => $password,
                'serveraddress' => 'index.docker.io/v1/',
                'email' => $email,
            ]));
        }
    }

    /**
     * @var string
     */
    private $registryAuth = '';

    /**
     * Create a request with cURL via the Docker socket
     *
     * @param  array|bool|int|float|object|resource|string|null  $body
     * @param  string[]  $headers
     * @param  string | null  $body
     * @return (bool|mixed|string)[]
     * @return array{response: mixed, code: mixed}
     */
    protected function call(string $url, string $method, $body = null, array $headers = [], int $timeout = -1): array
    {
        $headers[] = 'Host: utopia-php'; // Fix Swoole headers bug with socket requests
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        switch ($method) {
            case 'GET':
                if (! is_null($body)) {
                    \curl_setopt($ch, CURLOPT_URL, $url.'?'.$body);
                }
                break;
            case 'POST':
                \curl_setopt($ch, CURLOPT_POST, 1);

                if (! is_null($body)) {
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
                break;
            case 'DELETE':
                \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

                if (! is_null($body)) {
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
                break;
        }

        $result = \curl_exec($ch);
        $responseCode = \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [
            'response' => $result,
            'code' => $responseCode,
        ];
    }

    /**
     * Create a request with cURL via the Docker socket
     * but process a Docker Stream Response
     *
     * @return (bool|mixed|string)[]
     *
     * @psalm-return array{response: bool|string, code: mixed, stdout: mixed, stderr: mixed}
     */
    protected function streamCall(string $url, int $timeout = -1): array
    {
        $body = \json_encode(['Detach' => false]);

        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, '/var/run/docker.sock');
        \curl_setopt($ch, CURLOPT_POST, 1);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $headers = [
            'Content-Type: application/json',
            'Content-Length: '.\strlen($body),
            'Host: utopia-php', // Fix Swoole headers bug with socket requests
        ];
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        /*
         * Exec logs come back with STDOUT+STDERR multiplexed into a single stream.
         * Each frame of the stream has the following format:
         *   header := [8]byte{STREAM_TYPE, 0, 0, 0, SIZE1, SIZE2, SIZE3, SIZE4}
         *     STREAM_TYPE is of the following: [0=>'stdin', 1=>'stdout', 2=>'stderr']
         *     SIZE1, SIZE2, SIZE3, SIZE4 are the four bytes of the uint32 size encoded as big endian.
         *     Following the header is the payload, which is the specified number of bytes of STREAM_TYPE.
         *
         * To assign the appropriate stream:
         *   - unpack as an unsigned char ('C*')
         *   - check the first byte of the header to assign stream
         *   - pack up stream, omitting the 8 bytes of header
         *   - concat to stream
         *
         * Reference: https://docs.docker.com/engine/api/v1.41/#operation/ContainerAttach
         */

        $stdout = '';
        $stderr = '';

        $isHeader = true;
        $currentHeader = null;
        $currentData = '';

        $callback = function (mixed $ch, string $str) use (&$stdout, &$stderr, &$isHeader, &$currentHeader, &$currentData): int {
            if (empty($str)) {
                return 0;
            }

            $originalSize = \strlen($str);

            while (\strlen($str) > 0) {
                if ($isHeader) {
                    $header = \unpack('Ctype/Cfill1/Cfill2/Cfill3/Nsize', $str);
                    $str = \substr($str, 8, null);
                    $isHeader = false;
                    $currentHeader = $header;
                } else {
                    $size = $currentHeader['size'];
                    $type = $currentHeader['type'];

                    if (\strlen($str) >= $size) {
                        $currentData .= \substr($str, 0, $size);
                        $str = \substr($str, $size, null);
                        $isHeader = true;
                        $currentHeader = null;

                        if ($type === 1) {
                            $stdout .= $currentData;
                        } else {
                            $stderr .= $currentData;
                        }
                        $currentData = '';
                    } else {
                        $currentHeader['size'] -= \strlen($str);
                        $currentData .= $str;
                        $str = '';
                    }
                }
            }

            return $originalSize; // must return full frame from callback
        };

        \curl_setopt($ch, CURLOPT_WRITEFUNCTION, $callback);

        \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $result = \curl_exec($ch);
        $responseCode = \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if (curl_errno($ch)) {
            if (\curl_errno($ch) === CURLE_OPERATION_TIMEOUTED) {
                throw new Timeout('Curl Error: '.curl_error($ch));
            } else {
                throw new Orchestration('Curl Error: '.curl_error($ch));
            }
        }

        return [
            'response' => $result,
            'code' => $responseCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * Create Network
     */
    public function createNetwork(string $name, bool $internal = false): bool
    {
        $body = \json_encode([
            'Name' => $name,
            'Internal' => $internal,
        ]);

        $result = $this->call('http://localhost/networks/create', 'POST', $body, [
            'Content-Type: application/json',
            'Content-Length: '.\strlen($body),
        ]);

        if ($result['code'] === 409) {
            throw new Orchestration('Network with name "'.$name.'" already exists: '.$result['response']);
        } elseif ($result['code'] !== 201) {
            throw new Orchestration('Error creating network: '.$result['response']);
        }

        return $result['response'];
    }

    /**
     * Remove Network
     */
    public function removeNetwork(string $name): bool
    {
        $result = $this->call('http://localhost/networks/'.$name, 'DELETE');

        if ($result['code'] === 404) {
            throw new Orchestration('Network with name "'.$name.'" does not exist: '.$result['response']);
        } elseif ($result['code'] !== 204) {
            throw new Orchestration('Error removing network: '.$result['response']);
        }

        return true;
    }

    /**
     * Connect a container to a network
     */
    public function networkConnect(string $container, string $network): bool
    {
        $body = \json_encode([
            'Container' => $container,
        ]);

        $result = $this->call('http://localhost/networks/'.$network.'/connect', 'POST', $body, [
            'Content-Type: application/json',
            'Content-Length: '.\strlen($body),
        ]);

        if ($result['code'] !== 200) {
            throw new Orchestration('Error attaching network: '.$result['response']);
        }

        return $result['code'] === 200;
    }

    /**
     * Disconnect a container from a network
     */
    public function networkDisconnect(string $container, string $network, bool $force = false): bool
    {
        $body = \json_encode([
            'Container' => $container,
            'Force' => $force,
        ]);

        $result = $this->call('http://localhost/networks/'.$network.'/disconnect', 'POST', $body, [
            'Content-Type: application/json',
            'Content-Length: '.\strlen($body),
        ]);

        if ($result['code'] !== 200) {
            throw new Orchestration('Error detatching network: '.$result['response']);
        }

        return $result['code'] === 200;
    }

    /**
     * Check if a network exists
     */
    public function networkExists(string $name): bool
    {
        $result = $this->call('http://localhost/networks/'.$name, 'GET');

        return $result['code'] === 200;
    }

    /**
     * Get usage stats of containers
     *
     * @param  array<string, string>  $filters
     * @return array<Stats>
     */
    public function getStats(?string $container = null, array $filters = []): array
    {
        // List ahead of time, since API does not allow listing all usage stats
        $containerIds = [];
        if ($container === null) {
            $containers = $this->list($filters);
            $containerIds = \array_map(fn ($c) => $c->getId(), $containers);
        } else {
            $containerIds[] = $container;
        }

        $list = [];

        foreach ($containerIds as $containerId) {
            try {
                $result = $this->call('http://localhost/containers/'.$containerId.'/stats?stream=false', 'GET');

                if ($result['code'] !== 200 || empty($result['response'])) {
                    continue; // Skip to the next container
                }

                $stats = \json_decode($result['response'], true);

                if (! isset($stats['id']) || ! isset($stats['precpu_stats']) || ! isset($stats['cpu_stats']) || ! isset($stats['memory_stats']) || ! isset($stats['networks'])) {
                    continue; // Skip to the next container
                }

                // Calculate CPU usage
                $cpuDelta = $stats['cpu_stats']['cpu_usage']['total_usage'] - $stats['precpu_stats']['cpu_usage']['total_usage'];
                $systemCpuDelta = $stats['cpu_stats']['system_cpu_usage'] - $stats['precpu_stats']['system_cpu_usage'];
                $numberCpus = $stats['cpu_stats']['online_cpus'];
                if ($systemCpuDelta > 0 && $cpuDelta > 0) {
                    $cpuUsage = ($cpuDelta / $systemCpuDelta) * $numberCpus;
                } else {
                    $cpuUsage = 0.0;
                }

                // Calculate memory usage (unsafe div /0)
                $memoryUsage = 0.0;
                if ($stats['memory_stats']['limit'] > 0 && $stats['memory_stats']['usage'] > 0) {
                    $memoryUsage = ($stats['memory_stats']['usage'] / $stats['memory_stats']['limit']) * 100.0;
                }

                // Calculate network I/O
                $networkIn = 0;
                $networkOut = 0;
                foreach ($stats['networks'] as $network) {
                    $networkIn += $network['rx_bytes'];
                    $networkOut += $network['tx_bytes'];
                }

                // Calculate disk I/O
                $diskRead = 0;
                $diskWrite = 0;
                if (isset($stats['blkio_stats']['io_service_bytes_recursive'])) {
                    foreach ($stats['blkio_stats']['io_service_bytes_recursive'] as $entry) {
                        if ($entry['op'] === 'Read') {
                            $diskRead += $entry['value'];
                        } elseif ($entry['op'] === 'Write') {
                            $diskWrite += $entry['value'];
                        }
                    }
                }

                // Calculate memory I/O (approximated)
                $memoryIn = $stats['memory_stats']['usage'] ?? 0;
                $memoryOut = $stats['memory_stats']['max_usage'] ?? 0;

                $list[] = new Stats(
                    containerId: $stats['id'],
                    containerName: \ltrim($stats['name'], '/'), // Remove '/' prefix
                    cpuUsage: $cpuUsage,
                    memoryUsage: $memoryUsage,
                    diskIO: ['in' => $diskRead, 'out' => $diskWrite],
                    memoryIO: ['in' => $memoryIn, 'out' => $memoryOut],
                    networkIO: ['in' => $networkIn, 'out' => $networkOut],
                );
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $list;
    }

    /**
     * List Networks
     */
    public function listNetworks(): array
    {
        $result = $this->call('http://localhost/networks', 'GET');

        $list = [];

        if ($result['code'] !== 200) {
            throw new Orchestration($result['response']);
        }

        foreach (\json_decode($result['response'], true) as $value) {
            if (isset($value['Name'])) {
                $parsedContainer = new Network(
                    \str_replace('/', '', $value['Name']),
                    $value['Id'],
                    $value['Driver'],
                    $value['Scope']
                );

                array_push($list, $parsedContainer);
            }
        }

        return $list;
    }

    /**
     * Pull Image
     */
    public function pull(string $image): bool
    {
        $result = $this->call('http://localhost/images/create', 'POST', \http_build_query([
            'fromImage' => $image,
        ]), [
            'X-Registry-Auth' => $this->registryAuth,
        ]);

        if ($result['code'] !== 200 && $result['code'] !== 204) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * List Containers
     *
     * @param  array<string, string>  $filters
     * @return Container[]
     */
    public function list(array $filters = []): array
    {
        $filtersSorted = [];

        foreach ($filters as $key => $value) {
            $filtersSorted[$key] = [$value];
        }

        $body = [
            'all' => true,
            'filters' => empty($filtersSorted) ? new stdClass() : json_encode($filtersSorted),
        ];

        $result = $this->call('http://localhost/containers/json'.'?'.\http_build_query($body), 'GET');

        $list = [];

        if ($result['code'] !== 200) {
            throw new Orchestration($result['response']);
        }

        foreach (\json_decode($result['response'], true) as $value) {
            if (isset($value['Names'][0])) {
                $parsedContainer = new Container(
                    \str_replace('/', '', $value['Names'][0]),
                    $value['Id'],
                    $value['Status'],
                    $value['Labels']
                );

                array_push($list, $parsedContainer);
            }
        }

        return $list;
    }

    /**
     * Run Container
     *
     * Creates and runs a new container, On success it will return a string containing the container ID.
     * On fail it will throw an exception.
     *
     * @param  string[]  $command
     * @param  string[]  $volumes
     * @param  array<string, string>  $vars
     * @param  array<string, string>  $labels
     */
    public function run(
        string $image,
        string $name,
        array $command = [],
        string $entrypoint = '',
        string $workdir = '',
        array $volumes = [],
        array $vars = [],
        string $mountFolder = '',
        array $labels = [],
        string $hostname = '',
        bool $remove = false,
        string $network = '',
        string $restart = self::RESTART_NO
    ): string {
        $result = $this->call('http://localhost/images/'.$image.'/json', 'GET');
        if ($result['code'] === 404 && ! $this->pull($image)) {
            throw new Orchestration('Missing image "'.$image.'" and failed to pull it.');
        }

        $parsedVariables = [];

        foreach ($vars as $key => $value) {
            $key = $this->filterEnvKey($key);
            $parsedVariables[$key] = $key.'='.$value;
        }

        $vars = $parsedVariables;

        $labels[$this->namespace.'-type'] = 'runtime';
        $labels[$this->namespace.'-created'] = (string) time();

        $body = [
            'Hostname' => $hostname,
            'Entrypoint' => $entrypoint,
            'Image' => $image,
            'Cmd' => $command,
            'WorkingDir' => $workdir,
            'Labels' => (object) $labels,
            'Env' => array_values($vars),
            'HostConfig' => [
                'Binds' => $volumes,
                'CpuQuota' => floatval($this->cpus) * 100000,
                'CpuPeriod' => 100000,
                'Memory' => intval($this->memory) * 1e+6, // Convert into bytes
                'MemorySwap' => intval($this->swap) * 1e+6, // Convert into bytes
                'AutoRemove' => $remove,
                'NetworkMode' => ! empty($network) ? $network : null,
                'RestartPolicy' => [
                    'Name' => $restart,
                ],
            ],
        ];

        if (! empty($mountFolder)) {
            $body['HostConfig']['Binds'][] = $mountFolder.':/tmp';
        }

        $body = array_filter($body, function ($value) {
            return ! empty($value);
        });

        $result = $this->call('http://localhost/containers/create?name='.$name, 'POST', json_encode($body), [
            'Content-Type: application/json',
            'Content-Length: '.\strlen(\json_encode($body)),
        ]);

        if ($result['code'] === 404) {
            throw new Orchestration('Container image "'.$image.'" not found.');
        } elseif ($result['code'] === 409) {
            throw new Orchestration('Container with name "'.$name.'" already exists.');
        } elseif ($result['code'] !== 201) {
            throw new Orchestration('Failed to create function environment: '.$result['response'].' Response Code: '.$result['code']);
        }

        $parsedResponse = json_decode($result['response'], true);
        $containerId = $parsedResponse['Id'];

        // Run Created Container
        $startResult = $this->call('http://localhost/containers/'.$containerId.'/start', 'POST', '{}');
        if ($startResult['code'] !== 204) {
            throw new Orchestration('Failed to start container: '.$startResult['response']);
        }

        return $containerId;
    }

    /**
     * Execute Container
     *
     * @param  string[]  $command
     * @param  array<string, string>  $vars
     */
    public function execute(
        string $name,
        array $command,
        string &$output,
        array $vars = [],
        int $timeout = -1
    ): bool {
        $parsedVariables = [];

        foreach ($vars as $key => $value) {
            $key = $this->filterEnvKey($key);
            $parsedVariables[$key] = $key.'='.$value;
        }

        $vars = $parsedVariables;

        $body = [
            'Env' => \array_values($vars),
            'Cmd' => $command,
            'AttachStdout' => true,
            'AttachStderr' => true,
        ];

        $result = $this->call('http://localhost/containers/'.$name.'/exec', 'POST', json_encode($body), [
            'Content-Type: application/json',
            'Content-Length: '.\strlen(\json_encode($body)),
        ], $timeout);

        if ($result['code'] !== 201) {
            throw new Orchestration('Failed to create execute command: '.$result['response'].' Response Code: '.$result['code']);
        }

        $parsedResponse = json_decode($result['response'], true);

        $result = $this->streamCall('http://localhost/exec/'.$parsedResponse['Id'].'/start', $timeout);

        $output = $result['stdout'].$result['stderr'];

        if ($result['code'] !== 200) {
            throw new Orchestration('Failed to create execute command: '.$result['response'].' Response Code: '.$result['code']);
        }

        $result = $this->call('http://localhost/exec/'.$parsedResponse['Id'].'/json', 'GET');

        if ($result['code'] !== 200) {
            throw new Orchestration('Failed to inspect status of execute command: '.$result['response'].' Response Code: '.$result['code']);
        }

        $parsedResponse = json_decode($result['response'], true);
        if ($parsedResponse['Running'] === true || $parsedResponse['ExitCode'] !== 0) {
            throw new Orchestration('Failed to execute command. Exit code: '.$parsedResponse['ExitCode']);
        }

        return true;
    }

    /**
     * Remove Container
     */
    public function remove(string $name, bool $force = false): bool
    {
        $result = $this->call('http://localhost/containers/'.$name.($force ? '?force=true' : ''), 'DELETE');

        if ($result['code'] !== 204) {
            throw new Orchestration('Failed to remove container: '.$result['response'].' Response Code: '.$result['code']);
        } else {
            return true;
        }
    }
}
