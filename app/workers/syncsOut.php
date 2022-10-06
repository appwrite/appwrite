<?php

use Ahc\Jwt\JWT;
use Appwrite\Resque\Worker;
use Utopia\App;
use Utopia\CLI\Console;

require_once __DIR__ . '/../init.php';

Console::title('Syncs out V1 Worker');
Console::success(APP_NAME . ' syncs out worker v1 has started');

class SyncsOutV1 extends Worker
{
    protected array $errors = [];

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
        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10);
        $token = $jwt->encode($data);

        foreach ($this->regions as $region => $host) {
            if ($currentRegion === $region) {
                continue;
            }
                $ch = curl_init($host . '/v1/syncs');
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

            for ($attempts = 0; $attempts < 6; $attempts++) {
                    curl_exec($ch);
                    $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($responseStatus === 200) {
                    break;
                }

                sleep(2);
            }
                curl_close($ch);
        }
    }


    public function shutdown(): void
    {
    }
}
