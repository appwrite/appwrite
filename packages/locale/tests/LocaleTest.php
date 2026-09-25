<?php

namespace Utopia\Locale\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Locale\Locale;

class LocaleTest extends TestCase
{
    /**
     * @var Locale
     */
    protected $locale = null;

    /**
     * Locale keeps its languages and exception mode in static state, which a
     * host process (such as Appwrite's test bootstrap) may already have set.
     */
    private bool $exceptions;

    public function setUp(): void
    {
        $this->exceptions = Locale::$exceptions;

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

        Locale::setLanguageFromJSON('hi-IN', realpath(__DIR__.'/hi-IN.json') ?: ''); // Set Hindi

        // Plural translations are ICU MessageFormat patterns with a `count` argument
        Locale::setLanguageFromArray('en-GB', [
            'minutes' => '{count, plural, one {in # minute} other {in # minutes}}',
            'categories' => '{count, plural, zero {zero} one {one} two {two} few {few} many {many} other {other}}',
            'broken' => '{count, plural, one {in # minute} other {in # minutes}}',
            'expires' => 'This code expires {{expire}}.',
            'invites' => '{count, plural, one {# invite left for {team}} other {# invites left for {team}}}',
        ]);

        Locale::setLanguageFromArray('ru-RU', [
            'minutes' => '{count, plural, one {через # минуту} few {через # минуты} many {через # минут} other {через # минуты}}',
            'expires' => 'Код истекает {{expire}}.',
            'broken' => '{count, plural, one {через # минуту}',
        ]);

        Locale::setLanguageFromArray('cs-CZ', ['minutes' => '{count, plural, one {# minuta} few {# minuty} other {# minut}}']);
        Locale::setLanguageFromArray('ja-JP', ['minutes' => '{count, plural, other {#分}}']);
        Locale::setLanguageFromArray('ar-AE', ['categories' => '{count, plural, zero {zero} one {one} two {two} few {few} many {many} other {other}}']);

        // Serbian reading English translations, with and without English plural rules
        Locale::setLanguageFromJSON('sr-RS', realpath(__DIR__.'/en-plurals.json') ?: '', 'en');
        Locale::setLanguageFromJSON('sr-Latn-RS', realpath(__DIR__.'/en-plurals.json') ?: '');

        $languages = Locale::getLanguages();
        $this->assertContains('en-US', $languages);
        $this->assertContains('he-IL', $languages);
        $this->assertContains('hi-IN', $languages);
    }

    public function tearDown(): void
    {
        Locale::$exceptions = $this->exceptions;
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

    public function testPlurals(): void
    {
        $locale = new Locale('ru-RU');

        $this->assertEquals('через 1 минуту', $locale->getPlural('minutes', 1));
        $this->assertEquals('через 2 минуты', $locale->getPlural('minutes', 2));
        $this->assertEquals('через 5 минут', $locale->getPlural('minutes', 5));
        $this->assertEquals('через 21 минуту', $locale->getPlural('minutes', 21));

        $locale->setDefault('cs-CZ');

        $this->assertEquals('1 minuta', $locale->getPlural('minutes', 1));
        $this->assertEquals('3 minuty', $locale->getPlural('minutes', 3));
        $this->assertEquals('5 minut', $locale->getPlural('minutes', 5));

        $locale->setDefault('ja-JP');

        $this->assertEquals('1分', $locale->getPlural('minutes', 1));
        $this->assertEquals('5分', $locale->getPlural('minutes', 5));

        // Arabic uses every plural category
        $locale->setDefault('ar-AE');

        $this->assertEquals('zero', $locale->getPlural('categories', 0));
        $this->assertEquals('one', $locale->getPlural('categories', 1));
        $this->assertEquals('two', $locale->getPlural('categories', 2));
        $this->assertEquals('few', $locale->getPlural('categories', 3));
        $this->assertEquals('many', $locale->getPlural('categories', 11));
        $this->assertEquals('other', $locale->getPlural('categories', 100));

        // Fractions have their own category
        $locale->setDefault('en-GB');

        $this->assertEquals('one', $locale->getPlural('categories', 1));
        $this->assertEquals('other', $locale->getPlural('categories', 1.5));

        $this->assertEquals('2 invites left for Appwrite', $locale->getPlural('invites', 2, arguments: ['team' => 'Appwrite']));
    }

    public function testPluralRules(): void
    {
        // Serbian puts 21 in the `one` category, English does not
        $this->assertEquals('in 21 minutes', (new Locale('sr-RS'))->getPlural('minutes', 21));
        $this->assertEquals('in 21 minute', (new Locale('sr-Latn-RS'))->getPlural('minutes', 21));
    }

    public function testPluralFallback(): void
    {
        $locale = new Locale('ru-RU');
        $locale->setFallback('en-GB');

        $this->assertEquals('Код истекает через 21 минуту.', $locale->getText('expires', plurals: ['expire' => ['minutes', 21]]));

        // A pattern that does not compile falls back to the fallback language
        $this->assertEquals('in 5 minutes', $locale->getPlural('broken', 5));

        // A translation served by the fallback language is filled with that language's plural
        $locale->setDefault('cs-CZ');

        $this->assertEquals('This code expires in 21 minutes.', $locale->getText('expires', plurals: ['expire' => ['minutes', 21]]));
    }

    public function testGetPluralDefault(): void
    {
        $locale = new Locale('en-GB');

        $this->assertEquals('in 5 minutes', $locale->getPlural('minutes', 5));
        $this->assertEquals('{{missing}}', $locale->getPlural('missing', 5));
        $this->assertEquals('soon', $locale->getPlural('missing', 5, default: 'soon'));
        $this->assertEquals(null, $locale->getPlural('missing', 5, default: null));
        $this->assertEquals('This code expires {{missing}}.', $locale->getText('expires', plurals: ['expire' => ['missing', 5]]));

        Locale::$exceptions = true;

        try {
            $locale->getPlural('missing', 5);
            $this->fail('Failed to throw exception when translation is missing');
        } catch (Exception $e) {
            $this->assertEquals('Key named "missing" not found', $e->getMessage());
        }

        try {
            (new Locale('ru-RU'))->getPlural('broken', 5);
            $this->fail('Failed to throw exception when the pattern is invalid');
        } catch (Exception $e) {
            $this->assertStringContainsString('is not a valid plural pattern', $e->getMessage());
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
