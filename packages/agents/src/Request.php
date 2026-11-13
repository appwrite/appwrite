<?php

namespace Utopia\Agents;

/**
 * Everything a model needs for one generation, independent of provider.
 *
 * Settings left null use the provider's default. $options carries raw
 * provider parameters keyed by adapter name, merged into that adapter's
 * payload last: ['anthropic' => ['metadata' => ['user_id' => 'u1']]].
 */
final readonly class Request
{
    /**
     * @param  list<Message>  $messages
     * @param  list<Tool>  $tools
     * @param  list<string>  $stop
     * @param  array<string, array<string, mixed>>  $options
     */
    public function __construct(
        public array $messages,
        public array $tools = [],
        public ?ToolChoice $toolChoice = null,
        public ?Schema $schema = null,
        public ?int $maxTokens = null,
        public ?float $temperature = null,
        public ?float $topP = null,
        public ?int $topK = null,
        public array $stop = [],
        public ?int $seed = null,
        public ?Effort $effort = null,
        public array $options = [],
    ) {
    }

    /**
     * System messages joined into one prompt, for providers that take the
     * system prompt separately.
     */
    public function system(): string
    {
        $system = [];
        foreach ($this->messages as $message) {
            if ($message->role === Role::System) {
                $system[] = $message->text();
            }
        }

        return implode("\n\n", $system);
    }

    /**
     * Raw parameters for one adapter.
     *
     * @return array<string, mixed>
     */
    public function options(string $adapter): array
    {
        return $this->options[$adapter] ?? [];
    }
}
