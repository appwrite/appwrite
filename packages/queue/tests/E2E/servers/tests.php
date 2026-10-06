<?php

declare(strict_types=1);

use Utopia\Queue;

function handleRequest(Queue\Message $job, ?string $aliasValue = null): void
{
    $type = $job->getPayload()['type'];
    $value = $job->getPayload()['value'] ?? null;

    if ($job->getTimestamp() === 0) {
        throw new Exception();
    }

    switch ($type) {
        case 'test_string':
            assert(is_string($value));

            break;
        case 'test_number':
            assert(is_numeric($value));

            break;
        case 'test_bool':
            assert(is_bool($value));

            break;
        case 'test_array':
            assert(is_array($value));
            assert(count($value) === 3);
            assert(array_diff([1, 2, 3], $value) === []);

            break;
        case 'test_assoc':
            assert(is_array($value));
            assert(count($value) === 4);
            assert($value['string'] === 'ipsum');
            assert($value['number'] === 123);
            assert($value['bool'] === true);
            assert($value['null'] === null);

            break;
        case 'test_alias':
            // payload's `value` field carries the expected resolved alias value
            assert($aliasValue === $value);

            break;
        case 'test_exception':
            throw new Exception('Forced failure for test_exception');
    }
}
