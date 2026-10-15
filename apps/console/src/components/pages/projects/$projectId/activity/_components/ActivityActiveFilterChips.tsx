import { X } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { Button } from '@/components/ui/button'
import {
  buildFilterTagFromCompactKey,
  type CompactFilterKey,
  type FilterColumn,
  type FilterMap,
} from '@/lib/table-filters'
import {
  resolveCountryDisplayName,
  type CountryLookups,
} from '@/lib/locale/country-lookups'

/** Render a "**Country** is **US**" tag with the bold parts emphasised. */
function TagText({ tag }: { tag: string }) {
  return (
    <>
      {tag.split('**').map((part, index) =>
        index % 2 === 1 ? (
          <span key={index} className="font-medium text-foreground">
            {part}
          </span>
        ) : (
          <span key={index}>{part}</span>
        ),
      )}
    </>
  )
}

/**
 * Removable chips for the active activity filters, shown under the toolbar
 * so it's always obvious the list is filtered. Date range stays on the
 * picker, not in this row.
 */
export function ActivityActiveFilterChips({
  filterMap,
  columns,
  countryLookups,
  onRemoveFilter,
  onClearAllFilters,
}: {
  filterMap: FilterMap
  columns: FilterColumn[]
  countryLookups?: CountryLookups | null
  onRemoveFilter: (key: CompactFilterKey) => void
  onClearAllFilters: () => void
}) {
  const t = useT()
  const keys = Array.from(filterMap.keys()).filter((key) => key.c !== 'time')

  if (keys.length === 0) return null

  return (
    <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2.5 sm:px-6">
      <span className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
        {t('Filtered by')}
      </span>
      {keys.map((key) => {
        const displayKey =
          key.c === 'country' && typeof key.v === 'string' && countryLookups
            ? { ...key, v: resolveCountryDisplayName(key.v, countryLookups) }
            : key
        const tag = buildFilterTagFromCompactKey(displayKey, columns).tag
        return (
          <span
            key={`${key.c}:${key.o}:${String(key.v)}`}
            className="inline-flex h-7 max-w-[20rem] items-center gap-1.5 rounded-md border border-border bg-muted/40 ps-2.5 pe-1 text-[12px] text-muted-foreground"
          >
            <span className="truncate">
              <TagText tag={tag} />
            </span>
            <button
              type="button"
              onClick={() => onRemoveFilter(key)}
              className="inline-flex h-5 w-5 shrink-0 cursor-pointer items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-label={t('Remove filter')}
            >
              <X className="h-3 w-3" />
            </button>
          </span>
        )
      })}
      {keys.length > 1 ? (
        <Button
          variant="ghost"
          size="sm"
          className="h-7 px-2 text-[12px] text-muted-foreground"
          onClick={onClearAllFilters}
        >
          {t('Clear all')}
        </Button>
      ) : null}
    </div>
  )
}
