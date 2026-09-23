<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter;

use PHPUnit\Framework\TestCase;

class Base extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function getLastRequest(): array
    {
        sleep(2);

        $request = json_decode(file_get_contents('http://127.0.0.1:15000/__last_request__'), true);
        $request['data'] = json_decode((string) $request['data'], true);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getLastEmail(): array
    {
        sleep(3);

        $emails = json_decode(file_get_contents('http://127.0.0.1:11080/email'), true);

        if ($emails && \is_array($emails)) {
            return end($emails);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  int  $deliveredTo  Every address the envelope carried, blind ones included.
     */
    protected function assertResponse(array $response, int $deliveredTo = 1): void
    {
        $this->assertEquals($deliveredTo, $response['deliveredTo'], var_export($response, true));
        $this->assertEquals('', $response['results'][0]['error'], var_export($response, true));
        $this->assertEquals('success', $response['results'][0]['status'], var_export($response, true));
    }
}
