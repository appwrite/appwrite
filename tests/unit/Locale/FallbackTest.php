<?php

declare(strict_types=1);

namespace Tests\Unit\Locale;

use PHPUnit\Framework\TestCase;
use Utopia\Locale\Locale;

final class FallbackTest extends TestCase
{
    protected function setUp(): void
    {
        $translationsDir = __DIR__ . '/../../../app/config/locale/translations';
        Locale::$exceptions = false;
        Locale::setLanguageFromJSON('en', $translationsDir . '/en.json');

        // Synthetic incomplete catalog: keep one real key, omit email preview so
        // the test does not depend on which shipped locales are still partial
        // (e.g. fr.json was completed in #12449).
        Locale::setLanguageFromArray('xx', [
            'emails.verification.subject' => 'Account verification (xx)',
        ]);
    }

    public function testIncompleteLocaleFallsBackToEnglishForMissingEmailKeys(): void
    {
        $locale = new Locale('xx');
        $locale->setFallback('en');

        $preview = $locale->getText('emails.verification.preview');

        $this->assertNotSame('{{emails.verification.preview}}', $preview);
        $this->assertSame(
            'Verify your email to activate your {{project}} account.',
            $preview
        );
        // Keys present in the incomplete locale stay local.
        $this->assertSame('Account verification (xx)', $locale->getText('emails.verification.subject'));
    }

    public function testFallbackMatchingIncompleteLocaleLeaksRawKeys(): void
    {
        $locale = new Locale('xx');
        // Mirrors the previous bug: fallback = request locale when that catalog is incomplete.
        $locale->setFallback('xx');

        $this->assertSame(
            '{{emails.verification.preview}}',
            $locale->getText('emails.verification.preview')
        );
    }
}
