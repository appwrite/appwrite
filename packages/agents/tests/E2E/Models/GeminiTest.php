<?php

namespace Utopia\Agents\Tests\E2E\Models;

use Utopia\Agents\Adapters\Gemini;
use Utopia\Agents\Model;

class GeminiTest extends Base
{
    protected function key(): string
    {
        return 'LLM_KEY_GEMINI';
    }

    protected function model(string $key): Model
    {
        return new Gemini($key, getenv('LLM_MODEL_GEMINI') ?: 'gemini-2.5-flash');
    }
}
