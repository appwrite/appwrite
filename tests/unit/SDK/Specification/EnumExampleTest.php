<?php

declare(strict_types=1);

namespace Tests\Unit\SDK\Specification;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Enum\Create as AttributeCreate;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Enum\Update as AttributeUpdate;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Enum\Create as ColumnCreate;
use Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Enum\Update as ColumnUpdate;
use Appwrite\Platform\Modules\Databases\Module;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The enum endpoints reject a default that is not one of the elements, so the
 * two examples they document have to agree. They are declared on separate
 * ->param() calls with nothing tying them together, which is exactly how a
 * documented example ends up being a request the endpoint refuses.
 */
final class EnumExampleTest extends TestCase
{
    /**
     * @return \Iterator<string, array{class-string}>
     */
    public static function provideEnumActions(): \Iterator
    {
        yield 'attribute create' => [AttributeCreate::class];
        yield 'attribute update' => [AttributeUpdate::class];
        yield 'column create' => [ColumnCreate::class];
        yield 'column update' => [ColumnUpdate::class];
    }

    protected function setUp(): void
    {
        // The namespaced constants these actions reference are declared in
        // Databases/Constants.php, which only Module.php requires. Autoloading
        // the module runs that require.
        \class_exists(Module::class);
    }

    #[DataProvider('provideEnumActions')]
    public function testDocumentedDefaultIsOneOfTheDocumentedElements(string $action): void
    {
        $params = new $action()->getParams();

        $default = $params['default']['example'] ?? '';
        $this->assertNotSame('', $default, 'the default example is what a caller copies');

        $elements = \json_decode($params['elements']['example'] ?? '', true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($elements);

        $this->assertContains(
            $default,
            $elements,
            'a default outside elements makes the generated example fail with "Default value not found in elements"',
        );
    }
}
