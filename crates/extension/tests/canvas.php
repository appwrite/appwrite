<?php

declare(strict_types=1);

// Utopia\Image\Canvas and Utopia\Image\Font from the utopia extension, with the package's value
// objects loaded straight from packages/image/src (no Composer):
//   cargo build -p utopia-extension
//   php -n -d extension=target/debug/libutopia.so crates/extension/tests/canvas.php   (.dylib on macOS)
use Utopia\Image\Blank;
use Utopia\Image\Border;
use Utopia\Image\Canvas;
use Utopia\Image\Exception\LimitExceededException;
use Utopia\Image\Exception\UnsupportedFormatException;
use Utopia\Image\Font;
use Utopia\Image\Format;
use Utopia\Image\Gravity;
use Utopia\Image\Limits;
use Utopia\Image\Path;
use Utopia\Image\Point;
use Utopia\Image\Text;
use Utopia\Image\Transform;

spl_autoload_register(function (string $class): void {
    $path = __DIR__ . '/../../../packages/image/src/' . str_replace('\\', '/', substr($class, strlen('Utopia\\Image\\'))) . '.php';
    if (str_starts_with($class, 'Utopia\\Image\\') && is_file($path)) {
        require $path;
    }
});

/** An 8-bit RGB PNG of one colour, built without an image extension. */
function png(int $width, int $height): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $rows = str_repeat("\0" . str_repeat("\x20\x80\xe0", $width), $height);

    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($rows))
        . $chunk('IEND', '');
}

function check(string $name, bool $passed): void
{
    echo $passed ? 'ok ' : 'FAIL ', $name, "\n";
    if (!$passed) {
        $GLOBALS['failed'] = true;
    }
}

function thrown(callable $call): string
{
    try {
        $call();
    } catch (Throwable $error) {
        return $error::class . ': ' . $error->getMessage();
    }

    return 'nothing';
}

/** @return array{int, int} */
function size(string $encoded): array
{
    return array_slice(getimagesizefromstring($encoded) ?: [0, 0], 0, 2);
}

foreach ([Canvas::class, Font::class] as $class) {
    $reflection = new ReflectionClass($class);
    check("$class is internal, final and readonly", $reflection->isInternal() && $reflection->isFinal() && $reflection->isReadOnly());
    check("$class has no static methods", $reflection->getMethods(ReflectionMethod::IS_STATIC) === []);
}

$canvas = new Canvas(png(16, 8));
check('header', [$canvas->width, $canvas->height, $canvas->orientation, $canvas->format] === [16, 8, 1, Format::Png]);
check('properties are read-only', str_starts_with(thrown(fn () => $canvas->width = 1), 'Exception: No setter'));
check('no dynamic properties', thrown(fn () => $canvas->other = 1) === 'Error: Cannot create dynamic property Utopia\Image\Canvas::$other');
check('not cloneable', str_starts_with(thrown(fn () => clone $canvas), 'Error: Trying to clone an uncloneable object'));
check('not serializable', thrown(fn () => serialize($canvas)) === "Exception: Serialization of 'Utopia\\Image\\Canvas' is not allowed");

$cropped = $canvas->apply(new Transform(width: 4, height: 4));
check('apply returns another canvas', $cropped !== $canvas && $cropped instanceof Canvas);
check('the source is unchanged', size($canvas->encode(Format::Png)) === [16, 8]);
check('cropped size', size($cropped->encode(Format::Png)) === [4, 4]);
check('the source size stays on the plan', [$cropped->width, $cropped->height] === [16, 8]);

$styled = $canvas->apply(new Transform(
    width: 8,
    height: 8,
    gravity: Gravity::TopLeft,
    focus: new Point(0.25, 0.5),
    opacity: 0.5,
    background: '#ffffff',
    border: new Border(1, '#ff0000'),
    radius: 2,
    rotation: 90,
));
check('jpeg output', substr($styled->encode(Format::Jpeg, 80), 0, 2) === "\xff\xd8");
check('gif output', substr($styled->encode(Format::Gif), 0, 3) === 'GIF');
check('webp output', substr($styled->encode(Format::Webp, 70), 8, 4) === 'WEBP');
check('steps chain in the order given', size($canvas->apply(new Transform(rotation: 90))->apply(new Transform(width: 4))->encode(Format::Png)) === [4, 8]);

check('heic output is a typed failure or a file', in_array(
    thrown(fn () => $canvas->encode(Format::Heic)),
    ['nothing', UnsupportedFormatException::class . ': HEIC output is not available'],
    true,
));
check('not an image', thrown(fn () => new Canvas('not an image')) === UnsupportedFormatException::class . ': Unsupported image format');
check('formats not accepted', thrown(fn () => new Canvas(png(4, 4), new Limits(formats: [Format::Jpeg])))
    === UnsupportedFormatException::class . ': PNG input is not accepted');
check('width limit', thrown(fn () => new Canvas(png(16, 8), new Limits(width: 8))) === LimitExceededException::class . ': Image exceeds the size or memory limits');
check('height limit', thrown(fn () => new Canvas(png(16, 8), new Limits(height: 4))) === LimitExceededException::class . ': Image exceeds the size or memory limits');
check('limits belong to one canvas', (new Canvas(png(16, 8)))->width === 16);
check('a quarter turn is held to the limits', thrown(fn () => (new Canvas(png(8, 4), new Limits(height: 4)))->apply(new Transform(rotation: 90))->encode(Format::Png))
    === LimitExceededException::class . ': Image exceeds the size or memory limits');
check('a blank canvas is held to the limits', thrown(fn () => new Canvas(new Blank(32, 4), new Limits(width: 16)))
    === LimitExceededException::class . ': Image exceeds the size or memory limits');

check('invalid colour', thrown(fn () => $canvas->apply(new Transform(background: 'no-such-colour'))) === 'InvalidArgumentException: Invalid color: no-such-colour');
check('invalid border colour', thrown(fn () => $canvas->apply(new Transform(border: new Border(1, 'nope')))) === 'InvalidArgumentException: Invalid color: nope');
check('quality range', thrown(fn () => $canvas->encode(Format::Jpeg, 101)) === 'InvalidArgumentException: Quality must be between 0 and 100');
check('another object as a transform', thrown(fn () => $canvas->apply(new stdClass())) === 'InvalidArgumentException: Expected Utopia\Image\Transform, stdClass given');
check('another object as a format', thrown(fn () => $canvas->encode(Gravity::Center)) === 'InvalidArgumentException: Expected Utopia\Image\Format, Utopia\Image\Gravity given');
check('another value as a source', thrown(fn () => new Canvas(12)) === 'InvalidArgumentException: Expected a string or a Utopia\Image\Blank');
check('value objects validate themselves', thrown(fn () => new Transform(opacity: 2.0)) === 'InvalidArgumentException: Opacity must be between 0 and 1'
    && thrown(fn () => new Point(0.5, 2.0)) === 'InvalidArgumentException: Coordinates must be between 0 and 1'
    && thrown(fn () => new Blank(0, 4)) === 'InvalidArgumentException: Width and height must be positive'
    && thrown(fn () => new Limits(memory: 0)) === 'InvalidArgumentException: Limits must be positive');

$font = new Font(__DIR__ . '/../../../app/assets/fonts/inter-v8-latin-regular.ttf');
$blank = new Canvas(new Blank(32, 16, '#000000'));
check('blank canvas', [$blank->width, $blank->height, $blank->orientation, $blank->format] === [32, 16, 1, null]);
$drawn = $blank->draw(new Path('M0 0h4v4h-4Z', '#ffffff', 2, 2, 2))->draw(new Text('A', $font, 12, '#ffffff', 16, 12));
check('drawn png size', size($drawn->encode(Format::Png)) === [32, 16]);
check('drawing changes the pixels', $drawn->encode(Format::Png) !== $blank->encode(Format::Png));
check('glyphs', $font->has('A') && !$font->has('山') && !$font->has(' '));
check('more than one character', thrown(fn () => $font->has('AB')) === 'InvalidArgumentException: Expected one character');
check('invalid path', thrown(fn () => $blank->draw(new Path('M0 0 Q bogus', '#fff'))) === 'InvalidArgumentException: Invalid path at offset 7');
check('missing font', str_starts_with(thrown(fn () => new Font('/no/such/font.ttf')), "InvalidArgumentException: Can't read font /no/such/font.ttf"));
check('not a font', str_starts_with(thrown(fn () => new Font(__FILE__)), 'InvalidArgumentException: Invalid font'));
check('another object as a drawing', thrown(fn () => $blank->draw(new stdClass())) === 'InvalidArgumentException: Expected Utopia\Image\Path or Utopia\Image\Text, stdClass given');

exit(empty($failed) ? 0 : 1);
