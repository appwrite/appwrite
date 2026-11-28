<?php

declare(strict_types=1);

// Router for `php -S`: answers every request with the headers it arrived with.
header('Content-Type: application/json');
echo json_encode(array_change_key_case(getallheaders(), CASE_LOWER));
