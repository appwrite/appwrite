<?php

use Ahc\Jwt\JWT;
use Appwrite\Resque\Worker;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Response as ResponseAlias;

require_once __DIR__ . '/../init.php';

Console::title('Syncs out V1 Worker');
Console::success(APP_NAME . ' syncs out worker v1 has started');

class SyncsOutV1 extends Worker
{
    private array $regions;

    public function getName(): string
    {
        return "syncs-out";
    }

    public function init(): void
    {
        $this->regions = Config::getParam('regions', []);
    }

    public function run(): void
    {

        $currentRegion = App::getEnv('_APP_REGION', 'nyc1');

        $data[] = $this->args['key'];
        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 600, 10);
        $token = $jwt->encode($data);

        if (!empty($this->args['region'])) {
            $this->regions = $this->regions[$this->args['region']];
        }

        foreach ($this->regions as $code => $region) {
            if ($currentRegion === $code) {
                continue;
            }

            $status = $this->send($region['domain'] . '/v1/edge', $token, ['keys' => $data]);

            if ($status !== Response::STATUS_CODE_OK) {
                $this->getConsoleDB()->createDocument('syncs', new Document([
                    'requestedAt' => DateTime::now(),
                    'regionOrg'  => $currentRegion,
                    'regionDest' => $code,
                    'keys' => $data,
                    'status' => $status,
                ]));
            }
        }
    }

    private function send($url, $token, $data): int
    {

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        for ($attempts = 0; $attempts < 3; $attempts++) {
            curl_exec($ch);
            $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($responseStatus === 200) {
                return $responseStatus;
            }

            sleep(2);
        }
        curl_close($ch);
        return $responseStatus;
    }


    public function shutdown(): void
    {
    }
}
