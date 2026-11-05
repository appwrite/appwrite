<?php

namespace Utopia\Migration\Destinations;

enum SchemaAction
{
    case Create;
    case Skip;
    case Overwrite;
}

enum OnDuplicate: string
{
    case Fail = 'fail';
    case Skip = 'skip';
    case Overwrite = 'overwrite';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_values(\array_map(fn (self $case) => $case->value, self::cases()));
    }

    /**
     * Coarse routing for re-migration. The caller follows up with a spec-match
     * check that overrides Overwrite to Skip when source and dest
     * already have identical spec — see DestinationAppwrite for the full flow.
     */
    public function resolveSchemaAction(
        bool $exists,
        ?string $sourceUpdatedAt = null,
        ?string $destUpdatedAt = null,
    ): SchemaAction {
        if (!$exists) {
            return SchemaAction::Create;
        }
        return match ($this) {
            self::Fail   => SchemaAction::Create,
            self::Skip   => SchemaAction::Skip,
            self::Overwrite => $this->sourceIsNewer($sourceUpdatedAt, $destUpdatedAt)
                ? SchemaAction::Overwrite
                : SchemaAction::Skip,
        };
    }

    private function sourceIsNewer(?string $source, ?string $dest): bool
    {
        $src = $this->parseTimestamp($source);
        $dst = $this->parseTimestamp($dest);
        return $src !== null && $dst !== null && $src > $dst;
    }

    /**
     * strtotime accepts '0000-00-00' leniently (returns a large negative epoch,
     * not false), so non-positive epochs are rejected too.
     */
    private function parseTimestamp(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $epoch = \strtotime($value);
        if ($epoch === false || $epoch <= 0) {
            return null;
        }
        return $epoch;
    }
}
