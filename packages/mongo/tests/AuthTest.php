<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Mongo\Auth;

class AuthTest extends TestCase
{
    public function testStartDefaultsToAdminAuthSource(): void
    {
        $auth = new Auth([
            'authcid' => 'root',
            'secret' => Auth::encodeCredentials('root', 'example'),
        ]);

        [$payload, $database] = $auth->start();

        $this->assertSame(1, $payload['saslStart']);
        $this->assertSame('admin', $database);
    }

    public function testStartUsesProvidedAuthSource(): void
    {
        $auth = new Auth([
            'authcid' => 'appuser',
            'secret' => Auth::encodeCredentials('appuser', 'example'),
            'authSource' => 'appdb',
        ]);

        [$payload, $database] = $auth->start();

        $this->assertSame(1, $payload['saslStart']);
        $this->assertSame('appdb', $database);
    }

    public function testContinueUsesProvidedAuthSource(): void
    {
        $auth = new Auth([
            'authcid' => 'appuser',
            'secret' => Auth::encodeCredentials('appuser', 'example'),
            'authSource' => 'appdb',
        ]);

        $auth->start();

        $data = new \stdClass();
        $data->conversationId = 1;
        $data->payload = new \MongoDB\BSON\Binary('r=' . $auth->getCnonce() . 'server,s=' . base64_encode('salt') . ',i=4096', 0);

        [$payload, $database] = $auth->continue($data);

        $this->assertSame(1, $payload['saslContinue']);
        $this->assertSame('appdb', $database);
    }
}
