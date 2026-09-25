<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Project\Http\Project\Platforms;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Android;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Apple;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Linux;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Web;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Windows;
use Appwrite\SDK\Method;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Validator;

final class BlankValuesTest extends TestCase
{
    private const array BLANK_VALUES = [
        'space' => ' ',
        'spaces' => '   ',
        'tab' => "\t",
        'line feed' => "\n",
        'no-break space' => "\u{00A0}",
        'ideographic space' => "\u{3000}",
        'zero width space' => "\u{200B}",
    ];

    /** @var array<string> */
    private array $processed = [];

    /** @var array<string> */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->processed = Method::$processed;
        $this->errors = Method::$errors;
    }

    protected function tearDown(): void
    {
        Method::$processed = $this->processed;
        Method::$errors = $this->errors;
    }

    /**
     * @return iterable<string, array{class-string<Action>, string}>
     */
    public static function textParameters(): iterable
    {
        $parameters = [
            'createAndroidPlatform' => [Android\Create::class, ['name', 'applicationId']],
            'updateAndroidPlatform' => [Android\Update::class, ['name', 'applicationId']],
            'createApplePlatform' => [Apple\Create::class, ['name', 'bundleIdentifier']],
            'updateApplePlatform' => [Apple\Update::class, ['name', 'bundleIdentifier']],
            'createLinuxPlatform' => [Linux\Create::class, ['name', 'packageName']],
            'updateLinuxPlatform' => [Linux\Update::class, ['name', 'packageName']],
            'createWindowsPlatform' => [Windows\Create::class, ['name', 'packageIdentifierName']],
            'updateWindowsPlatform' => [Windows\Update::class, ['name', 'packageIdentifierName']],
            'createWebPlatform' => [Web\Create::class, ['name', 'key', 'type']],
            'updateWebPlatform' => [Web\Update::class, ['name', 'key']],
        ];

        foreach ($parameters as $method => [$action, $names]) {
            foreach ($names as $name) {
                yield "{$method} {$name}" => [$action, $name];
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function blankWebValues(): iterable
    {
        foreach (['hostname', 'key'] as $parameter) {
            foreach (self::BLANK_VALUES as $label => $value) {
                yield "{$parameter} {$label}" => [$parameter, $value];
            }
        }
    }

    /**
     * @param class-string<Action> $action
     */
    #[DataProvider('textParameters')]
    public function testTextParameterRejectsBlankValues(string $action, string $parameter): void
    {
        $validator = $this->validator($action, $parameter);

        $accepted = \array_keys(\array_filter(self::BLANK_VALUES, $validator->isValid(...)));

        $this->assertSame([], $accepted, "{$parameter} accepts blank values");
    }

    /**
     * @param class-string<Action> $action
     */
    #[DataProvider('textParameters')]
    public function testTextParameterAcceptsText(string $action, string $parameter): void
    {
        $validator = $this->validator($action, $parameter);

        $this->assertTrue($validator->isValid('com.example.app'));
        $this->assertTrue($validator->isValid(' My App '));
    }

    #[DataProvider('blankWebValues')]
    public function testWebCreateRejectsBlankValueBeforeDatabaseAccess(string $parameter, string $value): void
    {
        $values = ['hostname' => 'app.example.com', 'key' => '', $parameter => $value];

        $this->assertBadRequest(fn () => (new Web\Create())->action(
            'unique()',
            'My Web App',
            $values['hostname'],
            $values['key'],
            '',
            $this->createStub(Request::class),
            $this->createStub(Response::class),
            $this->createStub(Event::class),
            new Document(['$id' => 'project', '$sequence' => '1']),
            $this->unusedDatabase(),
            new Authorization(),
        ));
    }

    #[DataProvider('blankWebValues')]
    public function testWebUpdateRejectsBlankValueBeforeDatabaseAccess(string $parameter, string $value): void
    {
        $values = ['hostname' => 'app.example.com', 'key' => '', $parameter => $value];

        $this->assertBadRequest(fn () => (new Web\Update())->action(
            'platform',
            'My Web App',
            $values['hostname'],
            $values['key'],
            $this->createStub(Response::class),
            $this->createStub(Event::class),
            $this->unusedDatabase(),
            new Authorization(),
            new Document(['$id' => 'project', '$sequence' => '1']),
        ));
    }

    /**
     * @param class-string<Action> $action
     */
    private function validator(string $action, string $parameter): Validator
    {
        $validator = (new $action())->getParams()[$parameter]['validator'];

        $this->assertInstanceOf(Validator::class, $validator);

        return $validator;
    }

    private function unusedDatabase(): Database
    {
        $database = $this->createMock(Database::class);
        $database->expects($this->never())->method($this->anything());

        return $database;
    }

    private function assertBadRequest(callable $action): void
    {
        try {
            $action();
        } catch (Exception $exception) {
            $this->assertSame(Exception::GENERAL_BAD_REQUEST, $exception->getType());

            return;
        }

        $this->fail('The blank value was accepted');
    }
}
