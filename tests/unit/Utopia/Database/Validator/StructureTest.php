<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Database\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Structure;
use Utopia\Database\Validator\StructureUpstream;

final class StructureTest extends TestCase
{
    private const string UPSTREAM_STRUCTURE_SHA256 = '992076de7126179f9b48560bf89dc27e377d79d0be5a4013634cc27cdc247b0c';

    public function testOverrideExtendsUpstreamStructure(): void
    {
        $parent = (new \ReflectionClass(Structure::class))->getParentClass();

        $this->assertNotFalse($parent);
        $this->assertSame(StructureUpstream::class, $parent->getName());
    }

    public function testUpstreamStructureUnchanged(): void
    {
        $path = dirname(__DIR__, 5) . '/vendor/utopia-php/database/src/Database/Validator/Structure.php';
        if (!\is_file($path)) {
            $this->markTestSkipped('utopia-php/database is not installed');
        }

        $this->assertSame(
            self::UPSTREAM_STRUCTURE_SHA256,
            \hash('sha256', (string) \file_get_contents($path)),
            'utopia-php/database Structure.php changed. Replace src/Database/Validator/StructureUpstream.php from that file and keep the class name StructureUpstream.'
        );
    }

    public function testCreateStillRequiresTheColumn(): void
    {
        $validator = $this->validator();

        $this->assertFalse($validator->isValid($this->row(note: null)));
        $this->assertSame(
            'Invalid document structure: Missing required attribute "note"',
            $validator->getDescription()
        );
    }

    public function testPartialUpdateAllowsStoredNull(): void
    {
        $validator = $this->validator(new Document([
            'title' => 'Hello',
            'note' => null,
        ]));

        $this->assertTrue($validator->isValid($this->row(note: null)), $validator->getDescription());
    }

    public function testPartialUpdateAllowsOmittedStoredNull(): void
    {
        $validator = $this->validator(new Document([
            'title' => 'Hello',
        ]));

        $row = $this->row(note: null);
        $row->removeAttribute('note');

        $this->assertTrue($validator->isValid($row), $validator->getDescription());
    }

    public function testUpdateCanSetTheRequiredColumn(): void
    {
        $validator = $this->validator(new Document([
            'title' => 'Hello',
            'note' => null,
        ]));

        $this->assertTrue($validator->isValid($this->row(note: 'now')), $validator->getDescription());
    }

    public function testUpdateCannotClearARequiredColumn(): void
    {
        $validator = $this->validator(new Document([
            'title' => 'Hello',
            'note' => 'kept',
        ]));

        $this->assertFalse($validator->isValid($this->row(note: null)));
        $this->assertSame(
            'Invalid document structure: Missing required attribute "note"',
            $validator->getDescription()
        );
    }

    public function testLegacyNullDoesNotSkipOtherAttributes(): void
    {
        $validator = $this->validator(new Document([
            'title' => 'Hello',
            'note' => null,
        ]));

        $row = $this->row(note: null);
        $row->setAttribute('title', 5);

        $this->assertFalse($validator->isValid($row));
        $this->assertStringContainsString('Attribute "title"', $validator->getDescription());
    }

    private function validator(?Document $current = null): Structure
    {
        return new Structure(
            new Document([
                '$id' => Database::METADATA,
                '$collection' => Database::METADATA,
                'attributes' => [
                    [
                        '$id' => 'title',
                        'type' => Database::VAR_STRING,
                        'format' => '',
                        'size' => 256,
                        'required' => true,
                        'signed' => true,
                        'array' => false,
                        'filters' => [],
                    ],
                    [
                        '$id' => 'note',
                        'type' => Database::VAR_STRING,
                        'format' => '',
                        'size' => 256,
                        'required' => true,
                        'signed' => true,
                        'array' => false,
                        'filters' => [],
                    ],
                ],
            ]),
            Database::VAR_INTEGER,
            currentDocument: $current,
        );
    }

    private function row(?string $note): Document
    {
        return new Document([
            '$collection' => 'posts',
            '$createdAt' => '2000-04-01T12:00:00.000+00:00',
            '$updatedAt' => '2000-04-01T12:00:00.000+00:00',
            'title' => 'Hello',
            'note' => $note,
        ]);
    }
}
