import { X } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { Button } from '@/components/ui/button'
import { buildFilterTagFromCompactKey } from '@/lib/table-filters'
import { useCountryLookups } from '@/lib/react-query/hooks'
import { resolveCountryDisplayName } from '@/lib/locale/country-lookups'
import {
  ANALYTICS_FILTER_COLUMNS,
  trafficKindOfFilterKey,
} from '@/lib/analytics/analytics-filters'
import { useAnalyticsFilters } from './analytics-filters-context'

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
 * Removable chips for the active page filters, shown under the overview
 * controls so it's always obvious the whole page is filtered.
 */
export function ActiveFilterChips() {
  const t = useT()
  const { filterMap, onRemoveFilter, onClearAllFilters } = useAnalyticsFilters()
  const { lookups: countryLookups } = useCountryLookups()

  if (filterMap.size === 0) return null

  return (
    <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-2.5 sm:px-6">
      <span className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
        {t('Filtered by')}
      </span>
      {Array.from(filterMap.keys()).map((key) => {
        // Show country names rather than ISO codes, like the Locations card.
        const displayKey =
          key.c === 'country' && typeof key.v === 'string' && countryLookups
            ? { ...key, v: resolveCountryDisplayName(key.v, countryLookups) }
            : key
        // The humans/bots filter is an empty-category query underneath; show
        // what it means rather than `Bot category is ""`.
        const trafficKind = trafficKindOfFilterKey(key)
        const tag = trafficKind
          ? `**${trafficKind === 'human' ? t('Humans') : t('Bots')}** ${t('only')}`
          : buildFilterTagFromCompactKey(displayKey, ANALYTICS_FILTER_COLUMNS).tag
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
      {filterMap.size > 1 ? (
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
