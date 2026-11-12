<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Documents;

use Appwrite\Utopia\Database\Documents\Project;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase
{
    public function testSessionKeyIsNamedByTheProject(): void
    {
        $this->assertSame('a_session_console', (new Project(['$id' => 'console']))->getSessionKey());
        $this->assertSame('a_session_my-app', (new Project(['$id' => 'my-app']))->getSessionKey());
    }

    public function testCallbackSchemeIsNamedByTheProject(): void
    {
        $this->assertSame('appwrite-callback-my-app', (new Project(['$id' => 'my-app']))->getCallbackScheme());
    }
}
