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
        Locale::setLanguageFromJSON('fr', $translationsDir . '/fr.json');
    }

    public function testIncompleteLocaleFallsBackToEnglishForMissingEmailKeys(): void
    {
        $locale = new Locale('fr');
        $locale->setFallback('en');

        $preview = $locale->getText('emails.verification.preview');

        $this->assertNotSame('{{emails.verification.preview}}', $preview);
        $this->assertSame(
            'Verify your email to activate your {{project}} account.',
            $preview
        );
        // Keys present in fr stay French.
        $this->assertSame('Vérification du compte', $locale->getText('emails.verification.subject'));
    }

    public function testFallbackMatchingIncompleteLocaleLeaksRawKeys(): void
    {
        $locale = new Locale('fr');
        // Mirrors the previous bug: fallback = _APP_LOCALE when that env is fr.
        $locale->setFallback('fr');

        $this->assertSame(
            '{{emails.verification.preview}}',
            $locale->getText('emails.verification.preview')
        );
    }
}
