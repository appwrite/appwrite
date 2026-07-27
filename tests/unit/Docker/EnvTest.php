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

    public function testUnknownEscapesPreserveBackslash(): void
    {
        // .env contains unknown escapes \q and \d; both must keep the backslash.
        $data = "_APP_PATTERN=\"prefix\\q and \\d digits\"\n";

        $env = new Env($data);

        $this->assertSame('prefix\\q and \\d digits', $env->getVar('_APP_PATTERN'));
    }

    public function testIndentedCommentDoesNotCaptureNextAssignment(): void
    {
        $data = "  # indented note\n_APP_DOMAIN=example.com\n   \n_APP_OPTIONS_FORCE_HTTPS=enabled\n";

        $env = new Env($data);

        $this->assertSame('example.com', $env->getVar('_APP_DOMAIN'));
        $this->assertSame('enabled', $env->getVar('_APP_OPTIONS_FORCE_HTTPS'));
        $this->assertArrayNotHasKey('  # indented note', $env->list());
        $this->assertArrayNotHasKey('# indented note', $env->list());
    }

    public function testLineWithoutAssignmentIsSkipped(): void
    {
        $data = "NOT_AN_ASSIGNMENT\n_APP_DOMAIN=example.com\n";

        $env = new Env($data);

        $this->assertSame('example.com', $env->getVar('_APP_DOMAIN'));
        $this->assertCount(1, $env->list());
    }

    public function testUnterminatedQuoteDoesNotAbsorbLaterAssignments(): void
    {
        $data = "_APP_BROKEN=\"partial-value\n_APP_DOMAIN=example.com\n_APP_OPTIONS_FORCE_HTTPS=enabled\n";

        $env = new Env($data);

        $this->assertSame('partial-value', $env->getVar('_APP_BROKEN'));
        $this->assertSame('example.com', $env->getVar('_APP_DOMAIN'));
        $this->assertSame('enabled', $env->getVar('_APP_OPTIONS_FORCE_HTTPS'));
    }

    public function testUnterminatedQuoteDoesNotAbsorbLaterQuotedAssignment(): void
    {
        $data = "_APP_BROKEN=\"partial-value\n_APP_DOMAIN=\"example.com\"\n_APP_OPTIONS_FORCE_HTTPS=enabled\n";

        $env = new Env($data);

        $this->assertSame('partial-value', $env->getVar('_APP_BROKEN'));
        $this->assertSame('example.com', $env->getVar('_APP_DOMAIN'));
        $this->assertSame('enabled', $env->getVar('_APP_OPTIONS_FORCE_HTTPS'));
    }
}
