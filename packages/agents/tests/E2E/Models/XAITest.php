<?php

namespace Utopia\Agents\Tests\E2E\Models;

use Utopia\Agents\Adapters\XAI;
use Utopia\Agents\Model;

class XAITest extends Base
{
    protected function key(): string
    {
        return 'LLM_KEY_XAI';
    }

    protected function model(string $key): Model
    {
        return new XAI($key, getenv('LLM_MODEL_XAI') ?: 'grok-4-fast-non-reasoning');
    }
}
