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
            if (array_key_exists('rules', $collection)) {
                foreach ($collection['rules'] as $check) {
                    $occurences = 0;
                    foreach ($collection['rules'] as $rule) {
                        if ($rule['key'] == $check['key']) {
                            $occurences++;
                        }
                    }
                    $this->assertEquals(1, $occurences);
                }
            }
        }
    }
}
