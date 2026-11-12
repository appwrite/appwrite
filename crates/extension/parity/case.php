<?php

// One operation on one file, in its own process so a crash or a hang costs one case:
//   php case.php <file> <operation> <output dir>   prints one JSON line.
require '/usr/src/code/vendor/autoload.php';

use Utopia\Image\Image;

// As app/config/storage/resource_limits.php sets it.
Image::setResourceLimit('memory', 1024 * 1024 * 64);

[$file, $operation, $out] = [$argv[1], $argv[2], $argv[3]];
// The preview endpoint's order: crop, opacity, background, border, radius, rotation.
$operations = [
    'thumb-jpg' => ['jpg', 80, fn (Image $image) => $image->crop(300, 300)],
    'gravity-webp' => ['webp', 75, fn (Image $image) => $image->crop(200, 100, Image::GRAVITY_TOP_LEFT)],
    'height-png' => ['png', -1, fn (Image $image) => $image->crop(0, 150)],
    'styled-png' => ['png', -1, fn (Image $image) => $image->crop(256, 256)->setOpacity(0.6)->setBackground('#336699')->setBorder(6, '#ff0000')->setBorderRadius(40)->setRotation(90)],
    'focal-avif' => ['avif', 60, fn (Image $image) => $image->crop(240, 180, Image::GRAVITY_CENTER, 0.2, 0.8)],
    'same-gif' => ['gif', -1, fn (Image $image) => $image->crop(160, 160)],
    'unset-jpg' => ['jpg', -1, fn (Image $image) => $image->crop(320, 0)],
];
[$type, $quality, $apply] = $operations[$operation];
$started = microtime(true);
$result = ['file' => basename($file), 'operation' => $operation];
try {
    $image = new Image(file_get_contents($file));
    $apply($image);
    $blob = $image->output($type, $quality);
    $name = basename($file) . ".$operation.$type";
    file_put_contents("$out/$name", $blob);
    $result += ['status' => 'ok', 'output' => $name, 'bytes' => strlen($blob)];
} catch (Throwable $error) {
    $result += ['status' => 'error', 'class' => $error::class, 'message' => substr($error->getMessage(), 0, 120)];
}
preg_match('/VmHWM:\s+(\d+)/', (string) @file_get_contents('/proc/self/status'), $peak);
echo json_encode($result + ['ms' => round((microtime(true) - $started) * 1000), 'peak_mb' => round(($peak[1] ?? 0) / 1024)]), "\n";
