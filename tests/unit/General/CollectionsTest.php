<?php

namespace Appwrite\Tests;

use PHPUnit\Framework\TestCase;

class CollectionsTest extends TestCase
{
    protected $collections;

    public function setUp(): void
    {
        $this->collections = require('app/config/collections.php');
    }

    public function tearDown(): void
    {
    }

    public function testDuplicateRules()
    {
        foreach ($this->collections as $key => $collection) {
            if (array_key_exists('attributes', $collection)) {
                foreach ($collection['attributes'] as $check) {
                    $occurences = 0;
                    foreach ($collection['attributes'] as $attribute) {
                        if ($attribute['$id'] == $check['$id']) {
                            $occurences++;
                        }
                    }
                    $this->assertEquals(1, $occurences);
                }
            }
        }
    }
}
