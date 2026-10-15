<?php

declare(strict_types=1);

namespace Utopia\Config\Parser;

use Utopia\Config\Exception\Parse;
use Utopia\Config\Parser;

class JSON extends Parser
{
    /**
     * @param \ReflectionClass<covariant object>|null $reflection
     * @return array<string, mixed>
     */
    public function parse(mixed $contents, ?\ReflectionClass $reflection = null): array
    {
        if (!\is_string($contents)) {
            throw new Parse('Contents must be a string.');
        }

        if ($contents === '' || $contents === '0') {
            return [];
        }

        $config = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Parse('Config file is not a valid JSON file.');
        }

        return $this->requireMap($config, 'Config file must decode to a JSON object.');
    }
}
