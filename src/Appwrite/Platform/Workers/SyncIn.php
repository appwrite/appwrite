<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Realtime;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Platform\Action;
use Utopia\Queue\Message;

class SyncIn extends Action
{
    public static function getName(): string
    {
        return 'sync-in';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Sync in worker')
            ->inject('message')
            ->inject('cache')
            ->callback(fn (Message $message, Cache $cache) => $this->action($message, $cache));
    }


    /**
     * @param Message $message
     * @param Cache $cache
     * @throws Exception
     */
    public function action(Message $message, Cache $cache): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $type = $payload['type'];
        $key = $payload['key'];
        $time = DateTime::now();

        switch ($type) {
            case 'cache':
                $cache->setListenersStatus(false);
                Console::log("[{$time}] Purging cache key   {$key}");
                $cache->purge($key);
                $cache->setListenersStatus(true);
                break;
            case 'realtime':
                Console::log("[{$time}] Sending realtime message");
                Realtime::send(
                    projectId: $key['projectId'],
                    payload: $key['payload'],
                    events: $key['events'],
                    channels: $key['channels'],
                    roles: $key['roles'],
                    options: $key['options']
                );
                break;
            case 'certificate':
                Console::log("[{$time}] Writing certificate for domain [{$key['domain']}]");

                $path = APP_STORAGE_CERTIFICATES . '/__' . $key['domain'];
                $filename = $key['domain'] . 'tar.gz';
                if (!file_exists($path)) {
                    mkdir($path, 0755, true);
                }

                $result = file_put_contents($path . '/' . $filename, base64_decode($key['contents']));
                if (empty($result)) {
                    Console::error('Can not write ' . $key['filename']);
                    break;
                }

                $stdout = '';
                $stderr = '';
                $result = Console::execute('cd ' . $path . '  && tar xvzf ' . $filename, '', $stdout, $stderr);
                if ($result === 1) {
                    Console::error('Can not open ' . $filename);
                }
                break;
            default:
                break;
        }
    }
}
