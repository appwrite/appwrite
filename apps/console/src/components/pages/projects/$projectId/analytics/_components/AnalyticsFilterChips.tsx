import { useT } from '@/lib/i18n/translate'
import { useCountryLookups } from '@/lib/react-query/hooks'
import { ActiveFilterChips } from '@/components/global/shared/ActiveFilterChips'
import {
  analyticsFilterColumns,
  trafficKindOfFilterKey,
} from '@/lib/analytics/analytics-filters'
import { useAnalyticsFilters } from './analytics-filters-context'

/**
 * Removable chips for the active page filters, shown under the overview
 * controls so it's always obvious the whole page is filtered.
 */
export function AnalyticsFilterChips() {
  const t = useT()
  const {
    filters,
    filterMap,
    onApplyFilter,
    onRemoveFilter,
    onClearAllFilters,
  } = useAnalyticsFilters()
  const { lookups: countryLookups } = useCountryLookups()

  return (
    <ActiveFilterChips
      filterMap={filterMap}
      columns={analyticsFilterColumns(filters)}
      countryLookups={countryLookups}
      onApplyFilter={onApplyFilter}
      onRemoveFilter={onRemoveFilter}
      onClearAllFilters={onClearAllFilters}
      // The humans/bots filter is an empty-category query underneath; show
      // what it means rather than `Bot category is ""`.
      fixedTag={(key) => {
        const trafficKind = trafficKindOfFilterKey(key)
        if (!trafficKind) return undefined
        return `**${trafficKind === 'human' ? t('Humans') : t('Bots')}** ${t('only')}`
      }}
    />
  )
}
