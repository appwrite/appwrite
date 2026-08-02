<?php

namespace Tests\Unit\Functions;

use Appwrite\Functions\StartCommand;
use PHPUnit\Framework\TestCase;

class StartCommandTest extends TestCase
{
    public function testEmptyDeploymentUsesDefault(): void
    {
        $this->assertSame(
            'bash helpers/server.sh',
            StartCommand::resolve('bash helpers/server.sh', '')
        );
    }

    public function testPersistedDefaultDoesNotCdIntoSource(): void
    {
        $default = 'bash helpers/server.sh';

        $this->assertSame(
            $default,
            StartCommand::resolve($default, $default)
        );
    }

    public function testPersistedFrameworkSsrDefaultDoesNotCdIntoSource(): void
    {
        $default = 'bash helpers/angular/server.sh';

        $this->assertSame(
            $default,
            StartCommand::resolve($default, $default)
        );
    }

    public function testCustomCommandCdsIntoSourceAndEscapes(): void
    {
        $this->assertSame(
            'cd /usr/local/server/src/function/ && npm start --prefix=\"\$HOME\"',
            StartCommand::resolve(
                'bash helpers/server.sh',
                'npm start --prefix="$HOME"'
            )
        );
    }

    public function testCustomCommandEscapesBackticksAndQuotes(): void
    {
        $this->assertSame(
            'cd /usr/local/server/src/function/ && echo \"hi\" && echo \`id\`',
            StartCommand::resolve(
                'bash helpers/server.sh',
                'echo "hi" && echo `id`'
            )
        );
    }
}
