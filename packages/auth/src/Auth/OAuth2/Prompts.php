<?php

declare(strict_types=1);

namespace Utopia\Auth\OAuth2;

use Utopia\Auth\Enums\Prompt;

/**
 * Parsed OpenID Connect prompt values for an authorization request.
 *
 * OIDC represents prompts as a space-delimited string, while application code
 * should work with typed values and the protocol rule that prompt=none cannot
 * be combined with any other prompt.
 */
class Prompts
{
    /**
     * @var list<Prompt>
     */
    private readonly array $prompts;

    /**
     * @param list<Prompt> $prompts
     */
    private function __construct(array $prompts)
    {
        if (\in_array(Prompt::None, $prompts, true) && \count($prompts) > 1) {
            throw new InvalidPromptException('prompt=none cannot be combined with other prompt values.');
        }

        $this->prompts = $prompts;
    }

    /**
     * Parse the OIDC prompt request parameter.
     *
     * Empty input means no prompt preference was requested. Duplicate prompt
     * values are collapsed while preserving the first-seen order.
     *
     * @throws InvalidPromptException
     */
    public static function fromString(string $prompt): self
    {
        if ($prompt === '') {
            return new self([]);
        }

        $values = array_values(array_filter(explode(' ', $prompt), static fn(string $value): bool => $value !== ''));
        $prompts = [];

        foreach ($values as $value) {
            $promptValue = Prompt::tryFrom($value);

            if ($promptValue === null) {
                throw new InvalidPromptException("Invalid prompt value '{$value}'.");
            }

            if (!\in_array($promptValue, $prompts, true)) {
                $prompts[] = $promptValue;
            }
        }

        return new self($prompts);
    }

    /**
     * Check whether a prompt value was requested.
     */
    public function contains(Prompt $prompt): bool
    {
        return \in_array($prompt, $this->prompts, true);
    }

    /**
     * Return prompt values for persistence or API boundaries.
     *
     * @return list<non-empty-string>
     */
    public function toArray(): array
    {
        return array_map(static fn(Prompt $prompt): string => $prompt->value, $this->prompts);
    }

    /**
     * Serialize prompt values back to the OIDC space-delimited format.
     */
    public function toString(): string
    {
        return implode(' ', $this->toArray());
    }
}
