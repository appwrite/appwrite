<?php

use Ahc\Jwt\JWT;
use Appwrite\Resque\Worker;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Database\Document;

require_once __DIR__ . '/../init.php';

Console::title('Syncs out V1 Worker');
Console::success(APP_NAME . ' syncs out worker v1 has started');

class SyncsOutV1 extends Worker
{
    private array $regions = [
       'fra1' => '172.17.0.1',
       'nyc1' => '172.17.0.1',
       'blr1' => '172.17.0.1',
    ];

    public function getName(): string
    {
        return "syncs-out";
    }

    public function init(): void
    {
    }

    public function run(): void
    {
        //TODO current region env implementation
        $currentRegion = 'nyc1';

        $data['keys'][] = $this->args['key'];
        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 600, 10);
        $token = $jwt->encode($data);

        if (!empty($this->args['region'])) {
            $this->regions = $this->regions[$this->args['region']];
        }

        foreach ($this->regions as $region => $host) {
            if ($currentRegion === $region) {
                continue;
            }

            $status = $this->send($host, $token, $data);
            if ($status !== 200) {
                $this->getConsoleDB()->createDocument('syncs', new Document([
                    'requestedAt' => DateTime::now(),
                    'region' => $region,
                    'keys' => $data,
                    'status' => $status,
                ]));
            }
        }
    }

    private function send($host, $token, $data): int
    {

        $ch = curl_init($host . '/v1/syncs');
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
