<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Label;

use Appwrite\Event\Label\Renderer;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class RendererTest extends TestCase
{
    public function testSubstitutesRequestAndResponseNamespaces(): void
    {
        $rendered = Renderer::render('database/{request.databaseId}/collection/{response.$id}', [
            'request' => ['databaseId' => 'db1'],
            'response' => ['$id' => 'col9'],
        ]);

        $this->assertSame('database/db1/collection/col9', $rendered);
    }

    public function testFallsBackToResponseForUnknownNamespace(): void
    {
        $rendered = Renderer::render('thing/{unknown.id}', [
            'response' => ['id' => 'r1'],
        ]);

        $this->assertSame('thing/r1', $rendered);
    }

    public function testSupportsUserAndProjectNamespaces(): void
    {
        $rendered = Renderer::render('user/{user.$id}/project/{project.$id}', [
            'user' => ['$id' => 'usr1'],
            'project' => new Document(['$id' => 'prj1']),
        ]);

        $this->assertSame('user/usr1/project/prj1', $rendered);
    }

    public function testLeavesUnresolvedTokensIntact(): void
    {
        $rendered = Renderer::render('database/{request.databaseId}', [
            'request' => [],
        ]);

        $this->assertSame('database/{request.databaseId}', $rendered);
    }

    public function testShortCircuitsWhenNoTemplate(): void
    {
        $this->assertSame('database/db1', Renderer::render('database/db1', []));
        $this->assertSame('', Renderer::render('', []));
    }
}
