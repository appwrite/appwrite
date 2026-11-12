<?php

namespace Utopia\Agents\Tests\E2E\Models;

use Utopia\Agents\Adapters\Perplexity;
use Utopia\Agents\Model;

class PerplexityTest extends Base
{
    protected function key(): string
    {
        return 'LLM_KEY_PERPLEXITY';
    }

    protected function model(string $key): Model
    {
        return new Perplexity($key, getenv('LLM_MODEL_PERPLEXITY') ?: 'sonar');
    }

    protected function supportsTools(): bool
    {
        return false;
    }
}
