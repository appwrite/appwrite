<?php

// Utopia\Image\Image from the utopia extension, with no Composer package loaded:
//   cargo build -p utopia-extension
//   php -n -d extension=target/debug/libutopia.so crates/extension/tests/image.php   (.dylib on macOS)
use Utopia\Image\Image;

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

$class = new ReflectionClass(Image::class);
check('internal class', $class->isInternal());
check('gravity constant', Image::GRAVITY_TOP_LEFT === 'top-left');
check('gravity types', Image::getGravityTypes() === [
    'center', 'top-left', 'top', 'top-right', 'left', 'right', 'bottom-left', 'bottom', 'bottom-right',
]);
$names = fn (string $method) => array_map(fn ($parameter) => $parameter->getName(), $class->getMethod($method)->getParameters());
check('crop parameters', $names('crop') === ['width', 'height', 'gravity', 'x', 'y']);
check('setBorder parameters', $names('setBorder') === ['borderWidth', 'borderColor']);
check('save parameters', $names('save') === ['path', 'type', 'quality']);

$image = new Image(png(16, 8));
check('crop chains', $image->crop(4, 4) === $image);
$out = $image->output('png');
check('png output', substr($out, 0, 8) === "\x89PNG\r\n\x1a\n");
check('cropped size', array_slice(getimagesizefromstring($out), 0, 2) === [4, 4]);

$image = new Image(png(16, 8));
$image->crop(8, 8, Image::GRAVITY_TOP_LEFT)->setBorderRadius(2)->setOpacity(0.5)->setRotation(90)->setBackground('#ffffff');
check('jpeg output', substr($image->output('jpg', 80), 0, 2) === "\xff\xd8");
check('save() without a path', substr($image->save(null, 'gif'), 0, 3) === 'GIF');
check('save() to an empty path', substr($image->save('', 'gif'), 0, 3) === 'GIF');

$path = sys_get_temp_dir() . '/utopia-extension-' . getmypid() . '/out.png';
check('save() to a path', $image->save($path, 'png') === null && is_file($path));
unlink($path);
rmdir(dirname($path));

// Without Composer the package's exception classes cannot be found, so they are \Exception here.
check('unknown type', thrown(fn () => $image->output('bmp')) === 'Exception: Unsupported output format: bmp');
check('half a focal point', thrown(fn () => $image->crop(2, 2, x: 0.5))
    === 'InvalidArgumentException: Both focal point coordinates are required');
check('focal point out of range', thrown(fn () => $image->crop(2, 2, x: 0.5, y: 2.0))
    === 'InvalidArgumentException: Focal point coordinates must be finite and between 0 and 1');
check('not an image', thrown(fn () => new Image('not an image')) === 'Exception: Unsupported image format');
check('negative crop size', thrown(fn () => $image->crop(-1, 2)) === 'InvalidArgumentException: Width and height must not be negative');
check('negative border', thrown(fn () => $image->setBorder(-1, '#ff0000')) === 'InvalidArgumentException: Border width must not be negative');
check('negative radius', thrown(fn () => $image->setBorderRadius(-1)) === 'InvalidArgumentException: Corner radius must not be negative');
check('unknown colour', thrown(fn () => $image->setBackground('no-such-colour')) === 'InvalidArgumentException: Invalid color: no-such-colour');
$unknown = (new Image(png(16, 8)))->crop(6, 4, 'no-such-gravity')->output('png');
check('unknown gravity is a centred focal point', $unknown === (new Image(png(16, 8)))->crop(6, 4, x: 0.5, y: 0.5)->output('png'));
check('argument type', str_starts_with(thrown(fn () => $image->crop('wide', 2)), 'TypeError: '));

$font = __DIR__ . '/../../../app/assets/fonts/inter-v8-latin-regular.ttf';
$drawn = Image::create(32, 16, '#000000');
check('create size', [$drawn->getWidth(), $drawn->getHeight(), $drawn->getOrientation()] === [32, 16, 1]);
check('path and text chain', $drawn->path('M0 0h4v4h-4Z', '#ffffff', 2, 2, 2) === $drawn && $drawn->text('A', $font, 12, '#ffffff', 16, 12) === $drawn);
check('drawn png size', array_slice(getimagesizefromstring($drawn->output('png')), 0, 2) === [32, 16]);
check('glyphs', Image::hasGlyph($font, 'A') && ! Image::hasGlyph($font, '山'));
check('more than one character', thrown(fn () => Image::hasGlyph($font, 'AB')) === 'InvalidArgumentException: Expected one character');
check('invalid path', thrown(fn () => $drawn->path('M0 0 Q bogus', '#fff')) === 'InvalidArgumentException: Invalid path at offset 7');
check('missing font', str_starts_with(thrown(fn () => $drawn->text('A', '/no/such/font.ttf', 12, '#fff', 0, 0)), "InvalidArgumentException: Can't read font /no/such/font.ttf"));
check('empty canvas', thrown(fn () => Image::create(0, 4)) === 'InvalidArgumentException: Width and height must be positive');

Image::setResourceLimit('memory', 64 * 1024 * 1024);
Image::setResourceLimit('unknown', 1);

exit(empty($failed) ? 0 : 1);
