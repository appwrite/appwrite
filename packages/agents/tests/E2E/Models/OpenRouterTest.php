<?php

namespace Utopia\Agents\Tests\E2E\Models;

use Utopia\Agents\Adapters\OpenRouter;
use Utopia\Agents\Model;

class OpenRouterTest extends Base
{
    protected function key(): string
    {
        return 'LLM_KEY_OPENROUTER';
    }

    protected function model(string $key): Model
    {
        return new OpenRouter($key, getenv('LLM_MODEL_OPENROUTER') ?: 'openai/gpt-4o-mini');
    }
}
