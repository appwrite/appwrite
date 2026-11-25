<?php

// A stand-in executor for ExecutorTest: answers an execution with a buffered
// (non-streaming) multipart envelope whose body is as long as the ?size= of the
// execution's path, which the client posts as a form field.
\parse_str((string) \parse_url((string) ($_POST['path'] ?? ''), \PHP_URL_QUERY), $query);
$size = (int) ($query['size'] ?? 0);
$boundary = 'executor-test';
$parts = [
    'statusCode' => '200',
    'headers' => '{"content-type":"text/plain"}',
    'body' => \str_repeat('a', $size),
];

\header('content-type: multipart/form-data; boundary=' . $boundary);
foreach ($parts as $name => $value) {
    echo '--' . $boundary . "\r\n";
    echo 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
    echo $value . "\r\n";
}
echo '--' . $boundary . "--\r\n";
