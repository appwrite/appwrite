<?php

declare(strict_types=1);

// Router for `php -S`: every request fails the way a provider outage does.
http_response_code(500);
header('Content-Type: application/json');
echo '{"message":"Internal Server Error"}';
