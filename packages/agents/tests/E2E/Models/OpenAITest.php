<?php

namespace Utopia\Agents\Tests\E2E\Models;

use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Model;

class OpenAITest extends Base
{
    protected function key(): string
    {
        return 'LLM_KEY_OPENAI';
    }

    protected function model(string $key): Model
    {
        return new OpenAI($key, getenv('LLM_MODEL_OPENAI') ?: 'gpt-5-mini');
    }
}
