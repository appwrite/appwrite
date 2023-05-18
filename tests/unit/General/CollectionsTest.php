<?php

namespace Tests\Unit\General;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Index;

class CollectionsTest extends TestCase
{
    /** @var array<mixed> $collections */
    protected array $collections;

    public function setUp(): void
    {
        $this->collections = require('app/config/collections.php');
    }

    public function testDuplicateRules(): void
    {
        foreach ($this->collections as $key => $collection) {
            if (array_key_exists('attributes', $collection)) {
                foreach ($collection['attributes'] as $check) {
                    $occurrences = 0;
                    foreach ($collection['attributes'] as $attribute) {
                        if ($attribute['$id'] == $check['$id']) {
                            $occurrences++;
                        }
                    }
                    $this->assertEquals(1, $occurrences);
                }
            }
        }
    }

    public function testValidateCollectionIndexes(): void
    {
        $validator = new Index();

        foreach ($this->collections as $collection) {
            $document = new Document();

            foreach ($collection['attributes'] as $attribute) {
                $document->setAttribute('attributes', new Document($attribute), Document::SET_TYPE_APPEND);
            }

            foreach ($collection['indexes'] as $index) {
                $document->setAttribute('indexes', new Document($index), Document::SET_TYPE_APPEND);
            }

            $this->assertTrue($validator->isValid($document));
        }
    }
}
