<?php

namespace Appwrite\Platform\Modules\Health\Http\Health\Stats;

use Appwrite\Utopia\Response;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Registry\Registry;
use Utopia\Storage\Device;
use Utopia\Storage\Storage;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getStats';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/health/stats')
            ->desc('Get system stats')
            ->groups(['api', 'health'])
            ->label('scope', 'root')
            ->label('docs', false)
            ->inject('response')
            ->inject('register')
            ->inject('deviceForFiles')
            ->callback($this->action(...));
    }

    public function action(Response $response, Registry $register, Device $deviceForFiles): void
    {
        $cache = $register->get('cache');

        $cacheStats = $cache->info();

        $response->json([
            'storage' => [
                'used' => Storage::human($deviceForFiles->getDirectorySize($deviceForFiles->getRoot() . '/')),
                'partitionTotal' => Storage::human($deviceForFiles->getPartitionTotalSpace()),
                'partitionFree' => Storage::human($deviceForFiles->getPartitionFreeSpace()),
            ],
            'cache' => [
                'uptime' => $cacheStats['uptime_in_seconds'] ?? 0,
                'clients' => $cacheStats['connected_clients'] ?? 0,
                'hits' => $cacheStats['keyspace_hits'] ?? 0,
                'misses' => $cacheStats['keyspace_misses'] ?? 0,
                'memory_used' => $cacheStats['used_memory'] ?? 0,
                'memory_used_human' => $cacheStats['used_memory_human'] ?? 0,
                'memory_used_peak' => $cacheStats['used_memory_peak'] ?? 0,
                'memory_used_peak_human' => $cacheStats['used_memory_peak_human'] ?? 0,
            ],
        ]);
    }
}
