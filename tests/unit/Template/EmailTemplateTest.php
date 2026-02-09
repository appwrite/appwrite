<?php

namespace Tests\Unit\Template;

use Appwrite\Template\EmailTemplate;
use PHPUnit\Framework\TestCase;
use Utopia\Locale\Locale;

class EmailTemplateTest extends TestCase
{
    public function testTranslateTokens(): void
    {
        $locale = new Locale('en');
        $locale->setFallback('en');

        $content = 'Subject: {{emails.magicSession.subject}}';
        $translated = EmailTemplate::translateTokens($content, $locale);

        $this->assertStringNotContainsString('emails.magicSession.subject', $translated);
    }

    public function testUnknownTokensArePreserved(): void
    {
        $locale = new Locale('en');
        $locale->setFallback('en');

        $content = 'Missing: {{emails.unknown.key}}';
        $translated = EmailTemplate::translateTokens($content, $locale);

        $this->assertStringContainsString('{{emails.unknown.key}}', $translated);
    }
}
