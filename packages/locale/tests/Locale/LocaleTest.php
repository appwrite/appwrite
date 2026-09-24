<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Locale\Locale;

class LocaleTest extends TestCase
{
    /**
     * @var Locale
     */
    protected $locale = null;

    public function setUp(): void
    {
        Locale::$exceptions = false; // Disable exceptions

        // Set English
        Locale::setLanguageFromArray('en-US', [
            'hello' => 'Hello',
            'world' => 'World',
            'helloPlaceholder' => 'Hello {{name}} {{surname}}!',
            'numericPlaceholder' => 'We have {{usersAmount}} users registered.',
            'multiplePlaceholders' => 'Lets repeat: {{word}}, {{word}}, {{word}}',
        ]);

        Locale::setLanguageFromArray('he-IL', ['hello' => 'שלום']); // Set Hebrew

        Locale::setLanguageFromJSON('hi-IN', realpath(__DIR__.'/../hi-IN.json') ?: ''); // Set Hindi

        $this->assertCount(3, Locale::getLanguages());
    }

    public function tearDown(): void
    {
    }

    public function testTexts(): void
    {
        $locale = new Locale('en-US');

        $this->assertEquals('Hello', $locale->getText('hello'));
        $this->assertEquals('World', $locale->getText('world'));

        $translations = $locale->getTranslations();
        $this->assertCount(5, $translations);
        $this->assertEquals(['hello' => 'Hello', 'world' => 'World', 'helloPlaceholder' => 'Hello {{name}} {{surname}}!', 'numericPlaceholder' => 'We have {{usersAmount}} users registered.', 'multiplePlaceholders' => 'Lets repeat: {{word}}, {{word}}, {{word}}'], $translations);

        $locale->setDefault('hi-IN');

        $this->assertEquals('Namaste', $locale->getText('hello'));
        $this->assertEquals('Duniya', $locale->getText('world'));

        $this->assertCount(2, $locale->getTranslations());

        $locale->setDefault('he-IL');

        $this->assertEquals('שלום', $locale->getText('hello'));
        // $this->assertEquals('empty', $locale->getText('world', 'empty')); Has been removed in 0.5.0

        $this->assertCount(1, $locale->getTranslations());

        // Test placeholders
        $locale->setDefault('en-US');

        $this->assertEquals('Hello Matej Bačo!', $locale->getText('helloPlaceholder', placeholders: [
            'name' => 'Matej',
            'surname' => 'Bačo',
        ]));
        $this->assertEquals('Hello Matej {{surname}}!', $locale->getText('helloPlaceholder', placeholders: [
            'name' => 'Matej',
        ]));
        $this->assertEquals('Hello {{name}} {{surname}}!', $locale->getText('helloPlaceholder'));

        $this->assertEquals('We have 12 users registered.', $locale->getText('numericPlaceholder', placeholders: [
            'usersAmount' => 6 + 6,
        ]));

        $this->assertEquals('Lets repeat: Appwrite, Appwrite, Appwrite', $locale->getText('multiplePlaceholders', placeholders: [
            'word' => 'Appwrite',
        ]));

        // Test exceptions
        $locale->setDefault('he-IL');

        Locale::$exceptions = true;

        try {
            $locale->getText('world');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(Exception::class, $exception);

            return;
        }

        $this->fail('No exception was thrown');
    }

    public function testFallback(): void
    {
        $locale = new Locale('he-IL');

        $this->assertEquals('שלום', $locale->getText('hello'));
        $this->assertEquals('{{world}}', $locale->getText('world'));
        $this->assertEquals('{{missing}}', $locale->getText('missing'));

        $locale->setFallback('en-US');

        $this->assertEquals('שלום', $locale->getText('hello'));
        $this->assertEquals('World', $locale->getText('world'));
        $this->assertEquals('{{missing}}', $locale->getText('missing'));

        Locale::$exceptions = true;
        try {
            $locale->getText('missing');
            $this->fail('Failed to throw exception when translation is missing');
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }
    }

    public function testGetTextDefault(): void
    {
        $locale = new Locale('en-US');

        $this->assertEquals('Hello', $locale->getText('hello'));
        $this->assertEquals('{{missing}}', $locale->getText('missing'));
        $this->assertEquals('A custom text', $locale->getText('missing', default: 'A custom text'));
        $this->assertEquals(null, $locale->getText('missing', default: null));
        $this->assertEquals('Sorry Matej, missing text', $locale->getText('missing', placeholders: ['name' => 'Matej'], default: 'Sorry {{name}}, missing text'));
    }
}
