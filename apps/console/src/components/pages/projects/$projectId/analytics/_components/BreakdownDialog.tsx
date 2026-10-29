import { useMemo, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { BreakdownTabBar } from './BreakdownTabBar'
import { AnalyticsValueMenu } from './AnalyticsValueMenu'
import { useT } from '@/lib/i18n/translate'
import {
  analyticsBreakdownQueryOptions,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import { BreakdownRow } from './BreakdownRow'
import { useAnalyticsFilters } from './analytics-filters-context'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'
import {
  ANALYTICS_FILTER_UNSUPPORTED_MESSAGE,
  analyticsFilterAttributeForDimension,
  isAnalyticsFilterShapeSupported,
} from '@/lib/analytics/analytics-filters'
import { BreakdownMessage, BreakdownSkeleton } from './BreakdownPanel'
import type { BreakdownTab } from './BreakdownPanel'

/** Rows added per "Load more". */
export const BREAKDOWN_DIALOG_PAGE_SIZE = 50
/**
 * Upper bound on rows requested. `listMetrics` has no offset, so paging works
 * by raising `limit`; this keeps a runaway "load more" from asking the API for
 * an unbounded ranking.
 */
export const BREAKDOWN_DIALOG_MAX_ROWS = 500

export type BreakdownDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  propertyId: string
  range: AnalyticsRange
  title: string
  description?: string
  tabs: BreakdownTab[]
  initialTabId?: string
  /** Which measure ranks and sizes the rows. */
  valueKey?: 'visitors' | 'events'
  valueLabel?: string
  renderLeading?: (
    entry: Models.AnalyticsMetric,
    index: number,
    tabId: string,
  ) => ReactNode
  rowColor?: (entry: Models.AnalyticsMetric, index: number) => string
  formatLabel?: (value: string, tabId: string) => string
  mono?: boolean
  emptyLabel?: string
  /** Makes rows clickable (e.g. plot an event). */
  onRowClick?: (entry: Models.AnalyticsMetric, tabId: string) => void
  /** Small badge after a row's label (e.g. "Plotted"). */
  renderBadge?: (entry: Models.AnalyticsMetric, tabId: string) => ReactNode
  /** Drop rows before ranking (e.g. automatic events from Custom events). */
  rowFilter?: (entry: Models.AnalyticsMetric) => boolean
  /** External URL for a row (pages, hostnames): adds an open-in-new icon. */
  rowHref?: (value: string, tabId: string) => string | undefined
}

/**
 * Full ranked list for a breakdown, in a modal: same tabs as the card, a
 * client-side filter over the loaded rows, and "Load more" that grows the
 * request by a page at a time.
 *
 * Mount it only while open (the panels do) so its tab, filter and page
 * count reset each time it is reopened.
 */
export function BreakdownDialog({
  open,
  onOpenChange,
  projectId,
  propertyId,
  range,
  title,
  description,
  tabs,
  initialTabId,
  valueKey = 'visitors',
  valueLabel,
  renderLeading,
  rowColor,
  formatLabel,
  mono = false,
  emptyLabel,
  onRowClick,
  renderBadge,
  rowFilter,
  rowHref,
}: BreakdownDialogProps) {
  const t = useT()
  const [activeTab, setActiveTab] = useState(initialTabId ?? tabs[0]?.id ?? '')
  const [limit, setLimit] = useState(BREAKDOWN_DIALOG_PAGE_SIZE)
  const [filter, setFilter] = useState('')

  const active = tabs.find((tab) => tab.id === activeTab) ?? tabs[0]

  const { filters, addEqualFilter, isFilterActive } = useAnalyticsFilters()
  const filterAttribute = analyticsFilterAttributeForDimension(active.dimension)
  const unsupported = !isAnalyticsFilterShapeSupported(filters, {
    kind: 'breakdown',
    dimension: active.dimension,
  })

  const baseOptions = analyticsBreakdownQueryOptions(
    projectId,
    propertyId,
    active.dimension,
    range,
    limit,
    true,
    filters,
  )
  const { data, isLoading, isFetching, error } = useQuery({
    ...baseOptions,
    // Keep the rows on screen while the next page loads, but never carry rows
    // across a tab switch (a different dimension). Key index 4 is the
    // dimension in analyticsBreakdownQueryOptions.
    placeholderData: (previous, previousQuery) =>
      previousQuery?.queryKey[4] === active.dimension ? previous : undefined,
  })

  // Raw rows from the API, before `rowFilter`: paging decides on these, since
  // a full page means the ranking may continue even if some rows are dropped.
  const fetchedCount = data?.breakdown.length ?? 0

  // The API ranks by visitors; re-rank when the dialog measures events.
  const rows = useMemo(() => {
    // Same rule as the cards: unknown rows are dropped before ranking.
    const breakdown = (data?.breakdown ?? []).filter(
      (entry) =>
        isKnownBreakdownValue(entry.value) && (!rowFilter || rowFilter(entry)),
    )
    return valueKey === 'visitors'
      ? breakdown
      : [...breakdown].sort((a, b) => (b[valueKey] ?? 0) - (a[valueKey] ?? 0))
  }, [data, valueKey, rowFilter])

  const labelFor = (entry: Models.AnalyticsMetric) =>
    entry.value
      ? (formatLabel?.(entry.value, active.id) ?? entry.value)
      : t('Unknown')

  const { filtered, columnTotal, max } = useMemo(() => {
    const total = rows.reduce((sum, entry) => sum + (entry[valueKey] ?? 0), 0)
    const query = filter.trim().toLowerCase()
    const matching = query
      ? rows.filter((entry) => {
          const raw = entry.value?.toLowerCase() ?? ''
          const display =
            (entry.value && formatLabel?.(entry.value, active.id)) || ''
          return raw.includes(query) || display.toLowerCase().includes(query)
        })
      : rows
    return {
      filtered: matching,
      columnTotal: total,
      max: rows.reduce((m, entry) => Math.max(m, entry[valueKey] ?? 0), 0),
    }
  }, [rows, filter, valueKey, formatLabel, active.id])

  // A full page means the ranking may continue; fewer rows means we have it all.
  const mightHaveMore =
    fetchedCount >= limit && limit < BREAKDOWN_DIALOG_MAX_ROWS
  const isLoadingMore = isFetching && fetchedCount > 0 && fetchedCount < limit

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {/* Fixed size (not content-sized): switching tabs, filtering or
          loading more never resizes the modal, only the list scrolls. */}
      <DialogContent
        className="flex h-[min(85vh,820px)] flex-col gap-0 overflow-hidden p-0 sm:max-w-4xl"
        disableAutoFocus
      >
        <DialogHeader className="border-b border-border px-5 pb-3 pt-5 text-start">
          <DialogTitle className="text-[15px]">{t(title)}</DialogTitle>
          {description ? (
            <DialogDescription className="text-[12px]">
              {t(description)}
            </DialogDescription>
          ) : null}
        </DialogHeader>

        <div className="flex flex-col gap-3 border-b border-border px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
          {tabs.length > 1 ? (
            <BreakdownTabBar
              tabs={tabs}
              value={active.id}
              onValueChange={(value) => {
                setActiveTab(value)
                setLimit(BREAKDOWN_DIALOG_PAGE_SIZE)
                setFilter('')
              }}
            />
          ) : (
            <span />
          )}
          <div className="relative sm:w-56">
            <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={filter}
              onChange={(event) => setFilter(event.target.value)}
              placeholder={t('Filter loaded rows...')}
              className="h-8 ps-8 text-[12px]"
            />
          </div>
        </div>

        {/* Column header */}
        <div className="flex items-center justify-between px-7 pt-3 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          <span>{t(active.label)}</span>
          <span className="flex items-center gap-3">
            <span>{t('Share')}</span>
            <span className="min-w-[50px] text-end">
              {valueLabel ?? (valueKey === 'events' ? t('Events') : t('Visitors'))}
            </span>
          </span>
        </div>

        {/* Takes all remaining height; messages centre inside it. */}
        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-2">
          {unsupported ? (
            <div className="h-full">
              <BreakdownMessage>
                {t(ANALYTICS_FILTER_UNSUPPORTED_MESSAGE)}
              </BreakdownMessage>
            </div>
          ) : error && rows.length === 0 ? (
            <div className="h-full">
              <BreakdownMessage>
                {error instanceof Error
                  ? error.message
                  : t('Could not load analytics data')}
              </BreakdownMessage>
            </div>
          ) : isLoading && rows.length === 0 ? (
            <BreakdownSkeleton rows={14} />
          ) : filtered.length === 0 ? (
            <div className="h-full">
              <BreakdownMessage>
                {filter
                  ? t('No loaded rows match this filter')
                  : t(active.emptyLabel ?? emptyLabel ?? 'No data in this range')}
              </BreakdownMessage>
            </div>
          ) : (
            <div className="space-y-0.5">
              {filtered.map((entry, index) => {
                const value = entry[valueKey] ?? 0
                const canFilter = !!filterAttribute && !!entry.value
                const isActive =
                  canFilter && isFilterActive(filterAttribute!, entry.value!)
                const row = (
                  <BreakdownRow
                    onClick={
                      onRowClick
                        ? undefined
                        : canFilter
                          ? () => {
                              addEqualFilter(filterAttribute!, entry.value!)
                              // Close so the filtered page is visible.
                              onOpenChange(false)
                            }
                          : undefined
                    }
                    active={isActive}
                    actionTitle={
                      isActive ? t('Remove filter') : t('Filter by this value')
                    }
                    label={labelFor(entry)}
                    value={value}
                    share={columnTotal > 0 ? (value / columnTotal) * 100 : 0}
                    barPercent={max > 0 ? (value / max) * 100 : 0}
                    leading={
                      <>
                        <span className="w-6 shrink-0 text-end font-mono text-[10px] text-muted-foreground">
                          {rows.indexOf(entry) + 1}
                        </span>
                        {renderLeading?.(entry, rows.indexOf(entry), active.id)}
                      </>
                    }
                    badge={renderBadge?.(entry, active.id)}
                    color={rowColor?.(entry, rows.indexOf(entry))}
                    mono={mono}
                    // Not when the whole row is a <button> (onRowClick):
                    // a link can't nest inside it.
                    href={
                      onRowClick || !entry.value
                        ? undefined
                        : rowHref?.(entry.value, active.id)
                    }
                  />
                )
                return onRowClick && entry.value ? (
                  <button
                    key={`${entry.value}-${index}`}
                    type="button"
                    className="block w-full cursor-pointer text-start"
                    onClick={() => onRowClick(entry, active.id)}
                  >
                    {row}
                  </button>
                ) : entry.value ? (
                  <AnalyticsValueMenu
                    key={`${entry.value}-${index}`}
                    dimension={active.dimension}
                    value={entry.value}
                    label={labelFor(entry)}
                    href={rowHref?.(entry.value, active.id)}
                  >
                    <div>{row}</div>
                  </AnalyticsValueMenu>
                ) : (
                  <div key={`${entry.value}-${index}`}>{row}</div>
                )
              })}
            </div>
          )}
        </div>

        <div className="flex items-center justify-between gap-3 border-t border-border px-5 py-3">
          <span className="text-[11px] text-muted-foreground">
            {filter
              ? `${filtered.length} ${t('of')} ${rows.length} ${t('loaded rows')}`
              : `${rows.length} ${t('rows')}${mightHaveMore ? '' : ` · ${t('all loaded')}`}`}
          </span>
          <Button
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
            disabled={!mightHaveMore || isFetching}
            onClick={() =>
              setLimit((current) =>
                Math.min(
                  current + BREAKDOWN_DIALOG_PAGE_SIZE,
                  BREAKDOWN_DIALOG_MAX_ROWS,
                ),
              )
            }
          >
            {isLoadingMore ? t('Loading...') : t('Load more')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
