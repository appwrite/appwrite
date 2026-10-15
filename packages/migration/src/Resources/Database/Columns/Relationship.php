<?php

namespace Utopia\Migration\Resources\Database\Columns;

use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\RelationshipSide;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Table;

class Relationship extends Column
{
    public function __construct(
        string  $key,
        Table   $table,
        string  $relatedTable,
        string  $relationType,
        bool    $twoWay = false,
        ?string $twoWayKey = null,
        string  $onDelete = RelationshipDeleteAction::Restrict->value,
        string  $side = RelationshipSide::Parent->value,
        string  $createdAt = '',
        string  $updatedAt = ''
    ) {
        parent::__construct(
            $key,
            $table,
            options: [
                'relatedCollection' => $relatedTable,
                'relationType' => $relationType,
                'twoWay' => $twoWay,
                'twoWayKey' => $twoWayKey,
                'onDelete' => $onDelete,
                'side' => $side,
            ],
            createdAt: $createdAt,
            updatedAt: $updatedAt
        );
    }

    /**
     * @param array{
     *     key: string,
     *     collection?: array{
     *         database: array{
     *             id: string,
     *             name: string,
     *         },
     *         name: string,
     *         id: string,
     *         documentSecurity: bool,
     *         permissions: ?array<string>
     *     },
     *     table?: array{
     *         database: array{
     *             id: string,
     *             name: string,
     *         },
     *         name: string,
     *         id: string,
     *         rowSecurity: bool,
     *         permissions: ?array<string>
     *     },
     *     options: array{
     *         relatedCollection: string,
     *         relationType: string,
     *         twoWay: bool,
     *         twoWayKey: ?string,
     *         onDelete: string,
     *         side: string,
     *     },
     *     createdAt: string,
     *     updatedAt: string,
     * } $array
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['key'],
            Table::fromArray($array['table'] ?? $array['collection']),
            relatedTable: $array['options']['relatedTable'] ?? $array['options']['relatedCollection'],
            relationType: $array['options']['relationType'],
            twoWay: $array['options']['twoWay'],
            twoWayKey: $array['options']['twoWayKey'],
            onDelete: $array['options']['onDelete'],
            side: $array['options']['side'],
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
        );
    }

    public function getType(): string
    {
        return Column::TYPE_RELATIONSHIP;
    }

    public function getRelatedTable(): string
    {
        return $this->options['relatedTable'] ?? $this->options['relatedCollection'];
    }

    public function getRelationType(): string
    {
        return $this->options['relationType'];
    }

    public function getTwoWay(): bool
    {
        return $this->options['twoWay'];
    }

    public function getTwoWayKey(): ?string
    {
        return $this->options['twoWayKey'];
    }

    public function getOnDelete(): string
    {
        return $this->options['onDelete'];
    }

    public function getSide(): string
    {
        return $this->options['side'];
    }
}
