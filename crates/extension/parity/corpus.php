<?php

// Builds the parity corpus with Imagick: valid images that cover what uploads look like, and
// damaged ones. Deterministic. php corpus.php <resources> <out> [<dir of .icc profiles>]
[$resources, $out, $profiles] = [$argv[1], $argv[2], $argv[3] ?? ''];
@mkdir("$out/valid", 0755, true);
@mkdir("$out/broken", 0755, true);

$photo = fn () => new Imagick("$resources/disk-a/kitten-1.jpg");
$alpha = fn () => new Imagick("$resources/resize/opacity_0.2.png");
$write = function (string $name, Imagick $image) use ($out): void {
    file_put_contents("$out/valid/$name", $image->getNumberImages() > 1 ? $image->getImagesBlob() : $image->getImageBlob());
};
$sized = function (Imagick $image, int $width, int $height): Imagick {
    $image->thumbnailImage($width, $height);

    return $image;
};
$exif = function (string $jpeg, int $orientation): string {
    $data = "Exif\0\0II*\0\x08\0\0\0\x01\0\x12\x01\x03\0\x01\0\0\0" . pack('v', $orientation) . "\0\0\0\0\0\0";

    return substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($data) + 2) . $data . substr($jpeg, 2);
};

// JPEG: encodings, colour spaces, subsampling, sizes, orientation.
foreach (['baseline' => [], 'progressive' => ['interlace' => Imagick::INTERLACE_PLANE], 'q5' => ['quality' => 5], 'q100' => ['quality' => 100]] as $name => $options) {
    $image = $sized($photo(), 900, 940);
    $image->setImageFormat('jpg');
    $image->setImageCompressionQuality($options['quality'] ?? 85);
    $image->setInterlaceScheme($options['interlace'] ?? Imagick::INTERLACE_NO);
    $write("jpeg-$name.jpg", $image);
}
foreach (['444' => '1x1', '422' => '2x1', '420' => '2x2'] as $name => $factor) {
    $image = $sized($photo(), 640, 669);
    $image->setImageFormat('jpg');
    $image->setSamplingFactors([$factor, '1x1', '1x1']);
    $write("jpeg-$name.jpg", $image);
}
$image = $sized($photo(), 640, 669);
$image->transformImageColorspace(Imagick::COLORSPACE_GRAY);
$image->setImageFormat('jpg');
$write('jpeg-gray.jpg', $image);
$image = $sized($photo(), 640, 669);
$image->transformImageColorspace(Imagick::COLORSPACE_CMYK);
$image->setImageFormat('jpg');
$write('jpeg-cmyk.jpg', $image);
foreach (['AdobeRGB1998', 'Display P3'] as $profile) {
    if (is_file("$profiles/$profile.icc")) {
        $image = $sized($photo(), 640, 669);
        $image->profileImage('icc', file_get_contents("$profiles/$profile.icc"));
        $image->setImageFormat('jpg');
        $write('jpeg-icc-' . strtolower(strtr($profile, ' ', '-')) . '.jpg', $image);
    }
}
$image = $sized($photo(), 600, 400);
$image->setImageFormat('jpg');
foreach (range(1, 8) as $orientation) {
    file_put_contents("$out/valid/jpeg-exif-$orientation.jpg", $exif($image->getImageBlob(), $orientation));
}
foreach (['1x1' => [1, 1], '1x400' => [1, 400], '400x1' => [400, 1], '3x3' => [3, 3], 'large' => [5200, 3400]] as $name => [$width, $height]) {
    $image = $photo();
    $image->resizeImage($width, $height, Imagick::FILTER_BOX, 1);
    $image->setImageFormat('jpg');
    $write("jpeg-$name.jpg", $image);
}

// PNG: colour types, depths, interlacing.
foreach (['rgb' => Imagick::IMGTYPE_TRUECOLOR, 'rgba' => Imagick::IMGTYPE_TRUECOLORALPHA, 'palette' => Imagick::IMGTYPE_PALETTE,
    'palette-alpha' => Imagick::IMGTYPE_PALETTEALPHA, 'gray' => Imagick::IMGTYPE_GRAYSCALE, 'gray-alpha' => Imagick::IMGTYPE_GRAYSCALEALPHA,
    'bilevel' => Imagick::IMGTYPE_BILEVEL] as $name => $type) {
    $image = $sized($alpha(), 500, 522);
    $image->setImageType($type);
    $image->setImageFormat('png');
    $write("png-$name.png", $image);
}
$image = $sized($alpha(), 500, 522);
$image->setImageDepth(16);
$image->setImageFormat('png');
$image->setOption('png:bit-depth', '16');
$write('png-16bit.png', $image);
$image = $sized($alpha(), 500, 522);
$image->setInterlaceScheme(Imagick::INTERLACE_PNG);
$image->setImageFormat('png');
$write('png-interlaced.png', $image);
$image = $alpha();
$image->setImageFormat('png');
$write('png-large-alpha.png', $image);

// Animations and the other formats.
$frames = new Imagick();
foreach ([0, 60, 120, 180, 240, 300] as $index => $hue) {
    $frame = $sized($photo(), 240, 251);
    $frame->modulateImage(100, 100, 100 + $hue / 1.8);
    $frame->setImageDelay(5 + $index * 7);
    $frame->setImageDispose([Imagick::DISPOSE_NONE, Imagick::DISPOSE_BACKGROUND, Imagick::DISPOSE_PREVIOUS][$index % 3]);
    $frames->addImage($frame);
}
$frames->setImageFormat('gif');
$write('gif-animated.gif', $frames->deconstructImages());
$frames->setImageFormat('webp');
$write('webp-animated.webp', $frames);
$image = $sized($alpha(), 320, 334);
$image->setImageFormat('gif');
$write('gif-transparent.gif', $image);
file_put_contents("$out/valid/gif-photo-animation.gif", file_get_contents("$resources/disk-a/kitten-3.gif"));
foreach (['lossy' => ['webp:lossless' => 'false'], 'lossless' => ['webp:lossless' => 'true']] as $name => $options) {
    $image = $sized($photo(), 700, 731);
    $image->setImageFormat('webp');
    foreach ($options as $key => $value) {
        $image->setOption($key, $value);
    }
    $write("webp-$name.webp", $image);
}
$image = $sized($alpha(), 500, 522);
$image->setImageFormat('webp');
$write('webp-alpha.webp', $image);
foreach (['avif' => $photo, 'avif-alpha' => $alpha] as $name => $source) {
    $image = $sized($source(), 600, 627);
    $image->setImageFormat('avif');
    $write("$name.avif", $image);
}
file_put_contents("$out/valid/heic-photo.heic", file_get_contents("$resources/resize/100x100.heic"));

// Damaged files: every valid file under 200 kB, cut short, with bytes flipped, overwritten or inserted.
mt_srand(20261111);
$count = 0;
foreach (glob("$out/valid/*") as $path) {
    $data = file_get_contents($path);
    if (strlen($data) > 200_000) {
        continue;
    }
    [$stem, $extension] = [pathinfo($path, PATHINFO_FILENAME), pathinfo($path, PATHINFO_EXTENSION)];
    for ($n = 0; $n < 24; $n++) {
        $bytes = $data;
        $length = strlen($bytes);
        // Mostly in the first 4 kB, where the headers a decoder trusts are.
        $at = fn () => mt_rand(0, 3) ? mt_rand(4, min($length, 4096) - 1) : mt_rand(4, $length - 1);
        switch ($n % 6) {
            case 0: $bytes = substr($bytes, 0, mt_rand(8, $length - 1)); break;
            case 1: foreach (range(1, mt_rand(1, 8)) as $_) { $bytes[$at()] = chr(mt_rand(0, 255)); } break;
            case 2: $start = $at(); $bytes = substr_replace($bytes, str_repeat(mt_rand(0, 1) ? "\xff" : "\0", mt_rand(1, 16)), $start, 0); break;
            case 3: $start = $at(); $size = mt_rand(2, 64); $bytes = substr_replace($bytes, str_repeat(chr(mt_rand(0, 255)), $size), $start, $size); break;
            case 4: $start = $at(); $bytes = substr($bytes, 0, $start) . substr($bytes, $start + mt_rand(1, 512)); break;
            case 5: $start = $at(); foreach (range(0, 3) as $offset) { if ($start + $offset < $length) { $bytes[$start + $offset] = "\xff"; } } break;
        }
        file_put_contents(sprintf('%s/broken/%s-%02d.%s', $out, $stem, $n, $extension), $bytes);
        $count++;
    }
}
printf("%d valid, %d broken\n", count(glob("$out/valid/*")), $count);
