<?php

namespace Tests\E2E;

use Tests\E2E\Client;
use PHPUnit\Framework\TestCase;

class Base extends TestCase
{
    /**
     * @var Client
     */
    protected $client = null;
    protected $endpoint = 'http://localhost/v1';

    protected function setUp(): void
    {
        $this->client = new Client();

        $this->client
            ->setEndpoint($this->endpoint)
        ;
    }

    protected function tearDown(): void
    {
        $this->client = null;
    }

    protected function getLastEmail():array
    {
        sleep(3);
        $emails = json_decode(file_get_contents('http://localhost:1080/email'), true);

        if($emails && is_array($emails)) {
            return end($emails);
        }

        return [];
    }
}
