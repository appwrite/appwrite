<?php

namespace Appwrite\Platform\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Extend\Exception;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\System\System;

class SyncOutDelivery extends Action
{
    public const MAX_SEND_ATTEMPTS = 4;

    public static function getName(): string
    {
        return 'sync-out-delivery';
    }

    /**
     * @throws Exception|\Exception
     */
    public function __construct()
    {

        $this
            ->desc('Region Syncs out worker')
            ->inject('message')
            ->inject('dbForConsole')
            ->callback(fn (Message $message, Database $dbForConsole) => $this->action($message, $dbForConsole));
    }


    /**
     * @param Message $message
     * @param Database $dbForConsole
     * @throws Exception
     */
    public function action(Message $message, Database $dbForConsole): void
    {

        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        if (!empty($payload['syncId'])) {
            try {
                $sync = $dbForConsole->getDocument('syncs', $payload['syncId']);
                //$regions = Config::getParam('regions', []);
                $destRegion = $sync->getAttribute('destRegion');
                $chunk = file_get_contents(APP_STORAGE_SYNCS . '/' . $sync->getAttribute('filename') . '.log');
                $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 600, 10);
                $token = $jwt->encode([]);
                //$status = $this->send($regions[$destRegion]['domain'] . '/v1/region/sync', $token, json_decode($chunk));
                $status = $this->send('http://appwrite/v1/region/sync', $token, json_decode($chunk));
                Console::log('[' . DateTime::now() . ']  Request ' . $sync->getId() . ' to ' . $destRegion . ' returned status ' . $status);

                $sync->setAttribute('logSentAt', DateTime::now());
                $sync->setAttribute('status', $status);
                $dbForConsole->updateDocument('syncs', $sync->getId(), $sync);
            } catch (\Throwable $th) {
                Console::log('[' . DateTime::now() . ']  Error: ' .$th->getMessage());
            }
        }
    }

    /**
     * @param string $url
     * @param string $token
     * @param array data
     * @return int
     */
    public function send(string $url, string $token, array $data): int
    {

        $boundary = uniqid();
        $delimiter = '-------------' . $boundary;
        $payload = '';
        $eol = "\r\n";

        console::warning('Sending ' . count($data) . 'to ' . $url);

        foreach ($data as $keys) {
            $payload .= "--" . $delimiter . $eol
                . 'Content-Disposition: form-data; name="keys[]"' . $eol . $eol
                . json_encode($keys) . $eol;
        }
        $payload .= "--" . $delimiter . "--" . $eol;
        $status = 404;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Origin-region-url: ' . App::getEnv('_APP_REGION'),
            'Content-type: multipart/form-data; boundary=' . $delimiter,
            'Content-Length: ' . strlen($payload)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        //curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");

        for ($attempts = 0; $attempts < self::MAX_SEND_ATTEMPTS; $attempts++) {
            curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($status === 200) {
                return $status;
            }

            sleep(1);
        }

        curl_close($ch);

        return $status;
    }
}
