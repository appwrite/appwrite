<?php

namespace Utopia\Agents\Tests\E2E\Models;

use Utopia\Agents\Adapters\Deepseek;
use Utopia\Agents\Model;

class DeepseekTest extends Base
{
    protected function key(): string
    {
        return 'LLM_KEY_DEEPSEEK';
    }

    protected function model(string $key): Model
    {
        return new Deepseek($key, getenv('LLM_MODEL_DEEPSEEK') ?: 'deepseek-chat');
    }
}
