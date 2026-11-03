<?php

namespace Utopia\Agents\Tests\E2E\Models;

use Utopia\Agents\Adapters\Anthropic;
use Utopia\Agents\Model;

class AnthropicTest extends Base
{
    protected function key(): string
    {
        return 'LLM_KEY_ANTHROPIC';
    }

    protected function model(string $key): Model
    {
        return new Anthropic($key, getenv('LLM_MODEL_ANTHROPIC') ?: 'claude-haiku-4-5');
    }
}
