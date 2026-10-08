<?php

namespace Appwrite\Databases;

use Utopia\Database\RelationshipDeleteAction;
use Utopia\Query\Schema\ForeignKeyAction;

/**
 * An option an update leaves out, or sends as null, keeps its stored value on both sides.
 */
final readonly class RelationshipUpdate
{
    /**
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * @param array<string, mixed> $options The options the request sends, null for each one it leaves out.
     */
    public function __construct(
        array $options,
        private string $key,
        private ?string $newKey = null,
    ) {
        $this->options = \array_filter($options, static fn (mixed $option): bool => $option !== null);
    }

    public function onDelete(): ?RelationshipDeleteAction
    {
        return isset($this->options['onDelete']) ? ForeignKeyAction::from($this->options['onDelete']) : null;
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public function options(array $stored): array
    {
        return \array_merge($stored, $this->options);
    }

    /**
     * @param array<string, mixed> $stored The options the related side stores.
     * @return array<string, mixed>
     */
    public function related(array $stored): array
    {
        $options = $this->options;

        if (!empty($this->newKey) && $this->newKey !== $this->key) {
            $options['twoWayKey'] = $this->newKey;
        }

        return \array_merge($stored, $options);
    }
}
