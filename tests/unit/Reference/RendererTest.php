<?php

declare(strict_types=1);

namespace Tests\Unit\Reference;

use Appwrite\Reference\Renderer;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class RendererTest extends TestCase
{
    public function testSubstitutesRequestAndResponseNamespaces(): void
    {
        $renderer = new Renderer(new Document([
            'request' => ['databaseId' => 'db1'],
            'response' => ['$id' => 'col9'],
        ]));

        $this->assertSame(
            'database/db1/collection/col9',
            $renderer->render('database/{request.databaseId}/collection/{response.$id}'),
        );
    }

    public function testFallsBackToResponseForUnknownNamespace(): void
    {
        $renderer = new Renderer(new Document([
            'response' => ['id' => 'r1'],
        ]));

        $this->assertSame('thing/r1', $renderer->render('thing/{unknown.id}'));
    }

    public function testSupportsUserAndProjectNamespaces(): void
    {
        $renderer = new Renderer(new Document([
            'user' => ['$id' => 'usr1'],
            'project' => new Document(['$id' => 'prj1']),
        ]));

        $this->assertSame(
            'user/usr1/project/prj1',
            $renderer->render('user/{user.$id}/project/{project.$id}'),
        );
    }

    public function testLeavesUnresolvedTokensIntact(): void
    {
        $renderer = new Renderer(new Document([
            'request' => [],
        ]));

        $this->assertSame(
            'database/{request.databaseId}',
            $renderer->render('database/{request.databaseId}'),
        );
    }

    public function testShortCircuitsWhenNoTemplate(): void
    {
        $renderer = new Renderer(new Document([]));

        $this->assertSame('database/db1', $renderer->render('database/db1'));
        $this->assertSame('', $renderer->render(''));
    }
}
