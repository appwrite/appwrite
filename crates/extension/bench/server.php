<?php

// A Swoole HTTP server that does what Appwrite's preview endpoints do with an
// image: decode, crop, encode. Whether Utopia\Image\Image is the PHP class
// (Imagick) or the utopia extension's depends only on php.ini.
//   GET /preview?src=photo.jpg&width=400&height=400&output=jpg&quality=80
require '/usr/src/code/vendor/autoload.php';

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Utopia\Image\Image;

// As app/config/storage/resource_limits.php sets it (_APP_IMAGES_RESOURCE_LIMIT_MEMORY); 0 leaves the default.
if (($limit = (int) (getenv('IMAGE_MEMORY_LIMIT') ?: 1024 * 1024 * 64)) > 0) {
    Image::setResourceLimit('memory', $limit);
}

$sources = [];
foreach (glob('/tmp/sources/*') as $path) {
    $sources[basename($path)] = file_get_contents($path);
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'heic' => 'image/heic'];

$server = new Server('0.0.0.0', 8080);
$server->set(['worker_num' => (int) (getenv('WORKERS') ?: 4), 'log_level' => SWOOLE_LOG_WARNING]);
$server->on('request', function (Request $request, Response $response) use ($sources, $types) {
    if ($request->server['request_uri'] === '/implementation') {
        $response->end((new ReflectionClass(Image::class))->isInternal() ? 'extension' : 'imagick');

        return;
    }
    $query = $request->get ?? [];
    $output = $query['output'] ?? 'jpg';
    $image = new Image($sources[$query['src'] ?? '']);
    $image->crop((int) ($query['width'] ?? 0), (int) ($query['height'] ?? 0));
    $response->header('Content-Type', $types[$output]);
    $response->end($image->output($output, (int) ($query['quality'] ?? -1)));
});
$server->start();
