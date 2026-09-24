<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Proofs;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Proofs\Phrase;

final class PhraseTest extends TestCase
{
    protected Phrase $phrase;

    protected function setUp(): void
    {
        $this->phrase = new Phrase();
    }

    public function testGenerate(): void
    {
        $proof = $this->phrase->generate();

        $this->assertNotEmpty($proof);
        $this->assertStringContainsString(' ', $proof); // Should contain spaces between words
        $this->assertMatchesRegularExpression('/^[a-zA-Z\s-]+$/', $proof); // Letters, spaces, and hyphens (e.g. "Quick-witted")
    }

    public function testHash(): void
    {
        $proof = $this->phrase->generate();
        $hash = $this->phrase->hash($proof);

        $this->assertNotEmpty($hash);
        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function testVerify(): void
    {
        $proof = $this->phrase->generate();
        $hash = $this->phrase->hash($proof);

        $this->assertTrue($this->phrase->verify($proof, $hash));
        $this->assertFalse($this->phrase->verify('wrong phrase here', $hash));
    }
}
