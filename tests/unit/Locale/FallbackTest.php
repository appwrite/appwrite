<?php

declare(strict_types=1);

namespace Tests\Unit\Locale;

use PHPUnit\Framework\TestCase;
use Utopia\Locale\Locale;

final class FallbackTest extends TestCase
{
    private const COMPLETE = 'test-fallback-complete';
    private const PARTIAL = 'test-fallback-partial';

    private bool $exceptions;

    protected function setUp(): void
    {
        $this->exceptions = Locale::$exceptions;
        Locale::$exceptions = false;

        // Synthetic catalogs, because shipped translations get completed over
        // time (see #12449) and asserting on their coverage is self-breaking.
        Locale::setLanguageFromArray(self::COMPLETE, [
            'emails.verification.subject' => 'Account Verification',
            'emails.verification.preview' => 'Verify your email to activate your {{project}} account.',
        ]);

        Locale::setLanguageFromArray(self::PARTIAL, [
            'emails.verification.subject' => 'Vérification du compte',
        ]);
    }

    protected function tearDown(): void
    {
        Locale::$exceptions = $this->exceptions;
    }

    public function testIncompleteLocaleFallsBackToCompleteLocaleForMissingEmailKeys(): void
    {
        $locale = new Locale(self::PARTIAL);
        $locale->setFallback(self::COMPLETE);

        $preview = $locale->getText('emails.verification.preview');

        $this->assertNotSame('{{emails.verification.preview}}', $preview);
        $this->assertSame(
            'Verify your email to activate your {{project}} account.',
            $preview
        );
        // Keys present in the requested locale are not overridden by the fallback.
        $this->assertSame('Vérification du compte', $locale->getText('emails.verification.subject'));
    }

    public function testFallbackMatchingIncompleteLocaleLeaksRawKeys(): void
    {
        $locale = new Locale(self::PARTIAL);
        // Mirrors the previous bug: fallback = _APP_LOCALE when that env is a partial locale.
        $locale->setFallback(self::PARTIAL);

        $this->assertSame(
            '{{emails.verification.preview}}',
            $locale->getText('emails.verification.preview')
        );
    }
}
