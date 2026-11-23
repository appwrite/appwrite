<?php

namespace Utopia\Agents;

use Utopia\Agents\Parts\Reasoning;
use Utopia\Agents\Parts\Text;
use Utopia\Agents\Parts\ToolCall;
use Utopia\Agents\Parts\ToolResult;

/**
 * One turn of a conversation: who said it and what it contains.
 */
final readonly class Message
{
    /**
     * @param  list<Part>  $parts
     */
    public function __construct(
        public Role $role,
        public array $parts,
    ) {
    }

    public static function system(string $text): self
    {
        return new self(Role::System, [new Text($text)]);
    }

    /**
     * @param  string|list<Part>  $content
     */
    public static function user(string|array $content): self
    {
        return new self(Role::User, \is_string($content) ? [new Text($content)] : $content);
    }

    /**
     * @param  string|list<Part>  $content
     */
    public static function assistant(string|array $content): self
    {
        return new self(Role::Assistant, \is_string($content) ? [new Text($content)] : $content);
    }

    /**
     * @param  list<ToolResult>  $results
     */
    public static function tool(array $results): self
    {
        return new self(Role::Tool, $results);
    }

    /**
     * All text parts joined together.
     */
    public function text(): string
    {
        $text = '';
        foreach ($this->parts as $part) {
            if ($part instanceof Text) {
                $text .= $part->text;
            }
        }

        return $text;
    }

    /**
     * All reasoning text joined together.
     */
    public function reasoning(): string
    {
        $text = '';
        foreach ($this->parts as $part) {
            if ($part instanceof Reasoning) {
                $text .= $part->text;
            }
        }

        return $text;
    }

    /**
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        $calls = [];
        foreach ($this->parts as $part) {
            if ($part instanceof ToolCall) {
                $calls[] = $part;
            }
        }

        return $calls;
    }

    /**
     * @return list<ToolResult>
     */
    public function toolResults(): array
    {
        $results = [];
        foreach ($this->parts as $part) {
            if ($part instanceof ToolResult) {
                $results[] = $part;
            }
        }

        return $results;
    }
}
