<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\Migration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class MigrationTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function stages(): \Iterator
    {
        yield 'finalizing reads as migrating' => ['finalizing', 'migrating'];
        yield 'init' => ['init', 'init'];
        yield 'processing' => ['processing', 'processing'];
        yield 'migrating' => ['migrating', 'migrating'];
        yield 'finished' => ['finished', 'finished'];
    }

    #[DataProvider('stages')]
    public function testClientsSeeOnlyMainsStages(string $stored, string $visible): void
    {
        $document = (new Migration())->filter(new Document(['stage' => $stored]));

        $this->assertSame($visible, $document->getAttribute('stage'), 'Main never stored a finalizing stage; it was still migrating while the success hooks ran.');
    }

    public function testResourceDataKeepsMainsDocumentation(): void
    {
        $rule = (new Migration())->getRules()['resourceData'];

        $this->assertSame('An array of objects containing the report data of the resources that were migrated.', $rule['description']);
        $this->assertSame('[{"resource":"Database","id":"public","status":"SUCCESS","message":""}]', $rule['example']);
    }
}
