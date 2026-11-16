<?php

namespace Appwrite\SDK\Specification;

use Utopia\OpenAPI\Parser;
use Utopia\OpenAPI\Specification;

final readonly class Release
{
    public function __construct(
        private string $directory,
        private string $version,
    ) {
    }

    public function read(string $family): string
    {
        $path = $this->directory . '/open-api3-' . $this->version . '-' . $family . '.json';

        if (!\file_exists($path)) {
            throw new \Exception('Spec file not found: ' . $path . '. Please run "docker compose exec appwrite specs --version=' . $this->version . '" first to generate the specs.');
        }

        return \file_get_contents($path);
    }

    public function staticSpecification(): Specification
    {
        $server = Parser::parse($this->read('server'));

        return Parser::parse([
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Appwrite',
                'description' => 'Appwrite backend as a service',
                'version' => $server->info->version,
                'license' => [
                    'name' => 'BSD-3-Clause',
                    'url' => 'https://raw.githubusercontent.com/appwrite/appwrite/master/LICENSE',
                ],
            ],
            'paths' => [],
        ]);
    }
}
