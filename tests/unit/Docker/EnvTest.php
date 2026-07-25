<?php

declare(strict_types=1);

namespace Tests\Unit\Docker;

use Appwrite\Docker\Env;
use Exception;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    protected ?Env $object = null;

    public function setUp(): void
    {
        $data = @file_get_contents(__DIR__ . '/../../resources/docker/.env');

        if ($data === false) {
            throw new Exception('Failed to read compose file');
        }

        $this->object = new Env($data);
    }

    public function testVars(): void
    {
        $this->object->setVar('_APP_TEST', 'value4');

        $this->assertSame('value1', $this->object->getVar('_APP_X'));
        $this->assertSame('value2', $this->object->getVar('_APP_Y'));
        $this->assertSame('value3', $this->object->getVar('_APP_Z'));
        $this->assertSame('value5=', $this->object->getVar('_APP_W'));
        $this->assertSame('value4', $this->object->getVar('_APP_TEST'));
    }

    public function testExport(): void
    {
        $this->assertSame(
            "_APP_X=\"value1\"\n_APP_Y=\"value2\"\n_APP_Z=\"value3\"\n_APP_W=\"value5=\"\n",
            $this->object->export()
        );
    }

    public function testMultilineDoubleQuotedPrivateKey(): void
    {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\nABCDEF\n-----END RSA PRIVATE KEY-----";
        $data = "_APP_VCS_GITHUB_PRIVATE_KEY=\"-----BEGIN RSA PRIVATE KEY-----\nABCDEF\n-----END RSA PRIVATE KEY-----\"\n_APP_OTHER=ok\n";

        $env = new Env($data);

        $this->assertSame($pem, $env->getVar('_APP_VCS_GITHUB_PRIVATE_KEY'));
        $this->assertSame('ok', $env->getVar('_APP_OTHER'));
    }

    public function testEscapedNewlineInDoubleQuotedValue(): void
    {
        $data = "_APP_VCS_GITHUB_PRIVATE_KEY=\"-----BEGIN RSA PRIVATE KEY-----\\nABCDEF\\n-----END RSA PRIVATE KEY-----\"\n";

        $env = new Env($data);

        $this->assertSame(
            "-----BEGIN RSA PRIVATE KEY-----\nABCDEF\n-----END RSA PRIVATE KEY-----",
            $env->getVar('_APP_VCS_GITHUB_PRIVATE_KEY')
        );
    }

    public function testRoundTripPreservesMultilineSecret(): void
    {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\nLINE1\nLINE2\n-----END RSA PRIVATE KEY-----";
        $env = new Env('');
        $env->setVar('_APP_VCS_GITHUB_PRIVATE_KEY', $pem);
        $env->setVar('_APP_DOMAIN', 'example.com');

        $exported = $env->export();
        $reloaded = new Env($exported);

        $this->assertSame($pem, $reloaded->getVar('_APP_VCS_GITHUB_PRIVATE_KEY'));
        $this->assertSame('example.com', $reloaded->getVar('_APP_DOMAIN'));
        $this->assertStringNotContainsString("\nLINE1\n", $exported);
        $this->assertStringContainsString('\\nLINE1\\n', $exported);
    }

    public function testEncodeValueEscapesSpecialCharacters(): void
    {
        $this->assertSame(
            'say\\"hi\\nnext\\$path',
            Env::encodeValue("say\"hi\nnext\$path")
        );
    }
}
