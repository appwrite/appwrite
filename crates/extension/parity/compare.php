<?php

// Reads both implementations' results and compares them case by case, with Imagick:
// outcome, size, frames, bytes and how far apart the pixels are. php compare.php <results dir>
$results = $argv[1];
$load = function (string $implementation) use ($results): array {
    $cases = [];
    foreach (file("$results/$implementation/cases.jsonl", FILE_IGNORE_NEW_LINES) as $line) {
        $case = json_decode($line, true);
        $cases[$case['file'] . ' ' . $case['operation']] = $case;
    }

    return $cases;
};
[$imagick, $extension] = [$load('imagick'), $load('extension')];

/** The first frame on magenta, so a difference in transparency is a difference in pixels. */
$decode = function (string $path): array {
    $image = new Imagick($path);
    $frames = $image->getNumberImages();
    $image->setFirstIterator();
    $first = $image->getImage();
    $first->transformImageColorspace(Imagick::COLORSPACE_SRGB);
    $first->setImageBackgroundColor('#ff00ff');
    $first = $first->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
    $first->setImagePage(0, 0, 0, 0);

    return [$first, $frames, $image->getImageFormat()];
};
$outcome = fn (array $case) => $case['status'] === 'ok' ? 'ok' : ($case['status'] === 'error' ? $case['class'] : $case['status']);

$rows = [];
$summary = ['valid' => [], 'broken' => []];
foreach ($imagick as $key => $a) {
    $b = $extension[$key] ?? ['status' => 'missing'];
    $kind = preg_match('/-\d\d\.\w+$/', $a['file']) ? 'broken' : 'valid';
    $row = ['case' => $key, 'kind' => $kind, 'imagick' => $outcome($a), 'extension' => $outcome($b),
        'ms' => [$a['ms'] ?? null, $b['ms'] ?? null], 'peak_mb' => [$a['peak_mb'] ?? null, $b['peak_mb'] ?? null]];
    if ($a['status'] === 'ok' && $b['status'] === 'ok') {
        [$first, $framesA, $formatA] = $decode("$results/imagick/files/{$a['output']}");
        [$second, $framesB, $formatB] = $decode("$results/extension/files/{$b['output']}");
        $row += ['bytes' => [$a['bytes'], $b['bytes']], 'frames' => [$framesA, $framesB], 'format' => [$formatA, $formatB],
            'size' => [$first->getImageWidth() . 'x' . $first->getImageHeight(), $second->getImageWidth() . 'x' . $second->getImageHeight()]];
        if ($row['size'][0] === $row['size'][1]) {
            $row['rmse'] = round($first->compareImages($second, Imagick::METRIC_ROOTMEANSQUAREDERROR)[1], 4);
        }
    }
    $verdict = match (true) {
        $row['imagick'] !== $row['extension'] => 'outcome differs',
        !isset($row['size']) => 'same failure',
        $row['size'][0] !== $row['size'][1] => 'size differs',
        $row['frames'][0] !== $row['frames'][1] => 'frames differ',
        $row['rmse'] > 0.05 => 'pixels differ',
        default => 'match',
    };
    $row['verdict'] = $verdict;
    $summary[$kind][$verdict] = ($summary[$kind][$verdict] ?? 0) + 1;
    $rows[] = $row;
}
file_put_contents("$results/report.json", json_encode(['summary' => $summary, 'cases' => $rows], JSON_PRETTY_PRINT));
echo json_encode($summary, JSON_PRETTY_PRINT), "\n";
