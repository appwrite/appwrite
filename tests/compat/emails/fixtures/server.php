<?php

/*
 * The `emails-http` fixture server (see ../services.sh): the import task's sources are the files of
 * fixtures/sources, which PHP's built-in server serves as they are (404 for any other path). Besides:
 *   /to/<path>        302 to /<path>
 *   /status/<code>    an empty answer with that status
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/to/')) {
    header('Location: ' . substr($path, 3), true, 302);

    return true;
}
if (str_starts_with($path, '/status/')) {
    http_response_code((int) substr($path, 8));

    return true;
}

return false;
