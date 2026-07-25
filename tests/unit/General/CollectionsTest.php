<?php

declare(strict_types=1);

namespace Tests\Unit\General;

use PHPUnit\Framework\TestCase;

final class CollectionsTest extends TestCase
{
    protected array $collections;

    public function setUp(): void
    {
        $this->collections = require('app/config/collections.php');
    }

    public function testDuplicateRules(): void
    {
        foreach ($this->collections as $key => $sections) {
            foreach ($sections as $key => $collection) {
                if (array_key_exists('attributes', $collection)) {
                    foreach ($collection['attributes'] as $check) {
                        $occurrences = 0;
                        foreach ($collection['attributes'] as $attribute) {
                            if ($attribute['$id'] == $check['$id']) {
                                $occurrences++;
                            }
                        }
                        $this->assertSame(1, $occurrences);
                    }
                }
            }
        }
    }
}
