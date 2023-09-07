<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V16 extends Filter
{
    // Convert 1.3 params to 1.4
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'functions.create':
                $content['commands'] = $this->getCommands($content['runtime'] ?? '');
                break;
            case 'functions.update':
                $content['commands'] = $this->getCommands($content['runtime'] ?? '');
                break;
            case 'functions.createExecution':
                $content['body'] = $content['data'] ?? '';
                unset($content['data']);
                break;
        }

        return $content;
    }

    private function getCommands(string $runtime): string
    {
        if (\str_starts_with($runtime, 'node')) {
            return 'npm install';
        } elseif (\str_starts_with($runtime, 'python')) {
            return 'pip install --no-cache-dir -r requirements.txt';
        } elseif (\str_starts_with($runtime, 'dart')) {
            return 'dart pub get';
        } elseif (\str_starts_with($runtime, 'php')) {
            return 'composer update --no-interaction --ignore-platform-reqs --optimize-autoloader --prefer-dist --no-dev';
        } elseif (\str_starts_with($runtime, 'ruby')) {
            return 'bundle install';
        } elseif (\str_starts_with($runtime, 'swift')) {
            return 'swift package resolve';
        } elseif (\str_starts_with($runtime, 'dotnet')) {
            return 'dotnet restore';
        }

        return '';
    }
}
