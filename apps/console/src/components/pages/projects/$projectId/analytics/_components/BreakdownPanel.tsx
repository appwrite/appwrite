import { useMemo, useState, type ReactNode } from 'react'
import { Maximize2 } from 'lucide-react'
import type { AnalyticsDimension, Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { BreakdownTabBar } from './BreakdownTabBar'
import { CountryMap } from './CountryMap'
import { AnalyticsValueMenu } from './AnalyticsValueMenu'
import { useAnalyticsCardTab } from '@/hooks/use-analytics-card-tab'
import {
  useAnalyticsBreakdown,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import { BreakdownRow, RowRank } from './BreakdownRow'
import { BreakdownDialog } from './BreakdownDialog'
import { MetricInfo, type AnalyticsMetricInfoKey } from './MetricInfo'
import { useAnalyticsFilters } from './analytics-filters-context'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'
import {
  ANALYTICS_FILTER_UNSUPPORTED_MESSAGE,
  analyticsFilterAttributeForDimension,
} from '@/lib/analytics/analytics-filters'

// ─── Fixed card geometry ────────────────────────────────────────────────────
//
// Every breakdown card reserves room for exactly COLLAPSED_ROWS rows plus the
// footer, whatever it is showing: skeleton, error, empty state, three rows or
// thirty. Switching tabs, ranges or comparison therefore never changes a
// card's height, so nothing below it jumps. "Show more" opens the full list
// in a modal rather than growing the card.

/** Rows shown before a card is expanded. */
export const BREAKDOWN_COLLAPSED_ROWS = 8
/** Matches BreakdownRow's `h-7`. */
export const BREAKDOWN_ROW_HEIGHT_PX = 28
/** Matches the list's `space-y-0.5`. */
export const BREAKDOWN_ROW_GAP_PX = 2
/** Height of the collapsed list area. */
export const BREAKDOWN_LIST_HEIGHT_PX =
  BREAKDOWN_COLLAPSED_ROWS * BREAKDOWN_ROW_HEIGHT_PX +
  (BREAKDOWN_COLLAPSED_ROWS - 1) * BREAKDOWN_ROW_GAP_PX
/** List area plus the footer slot (`mt-3` + `h-7`). */
export const BREAKDOWN_BODY_FILL_HEIGHT_PX = BREAKDOWN_LIST_HEIGHT_PX + 12 + 28

/** Placeholder rows, same geometry as real rows. */
export function BreakdownSkeleton({
  rows = BREAKDOWN_COLLAPSED_ROWS,
}: {
  rows?: number
}) {
  return (
    <div className="space-y-0.5" aria-hidden>
      {Array.from({ length: rows }).map((_, index) => (
        <div
          key={index}
          className="h-7 animate-pulse rounded-md bg-muted/50"
          // Fade later rows so the skeleton doesn't read as eight real rows.
          style={{ opacity: 1 - index * 0.1 }}
        />
      ))}
    </div>
  )
}

/** Centred message inside the reserved list area (empty / error). */
export function BreakdownMessage({ children }: { children: ReactNode }) {
  return (
    <div className="flex h-full items-center justify-center px-4 text-center text-[13px] text-muted-foreground">
      {children}
    </div>
  )
}

/**
 * The fixed list area plus a footer slot that is always present, so a card
 * with a "Show more" button and one without are the same height. Cards never
 * grow: the full list opens in `BreakdownDialog`.
 */
export function BreakdownBody({
  canShowMore,
  onShowMore,
  fill = false,
  children,
}: {
  /** Use the footer's space too (no "Show more"). */
  fill?: boolean
  /** True when there are more rows than fit (or the ranking may continue). */
  canShowMore: boolean
  onShowMore: () => void
  children: ReactNode
}) {
  const t = useT()

  if (fill) {
    // Same total height, footer slot included, for content that should use
    // the whole body (the map).
    return (
      <div className="p-4">
        <div
          style={{ height: BREAKDOWN_BODY_FILL_HEIGHT_PX }}
          className="overflow-hidden"
        >
          {children}
        </div>
      </div>
    )
  }

  return (
    <div className="p-4">
      <div
        style={{ height: BREAKDOWN_LIST_HEIGHT_PX }}
        className="overflow-hidden"
      >
        {children}
      </div>
      <div className="mt-3 flex h-7 justify-center">
        {canShowMore ? (
          <Button
            variant="ghost"
            size="sm"
            className="h-7 text-[11px]"
            onClick={onShowMore}
          >
            {t('Show more')}
            <Maximize2 className="ms-1.5 h-3 w-3" />
          </Button>
        ) : null}
      </div>
    </div>
  )
}

/** Card header: title, optional info hint, one-line description. */
export function BreakdownHeader({
  title,
  description,
  info,
  right,
}: {
  title: string
  description?: string
  info?: AnalyticsMetricInfoKey
  right?: ReactNode
}) {
  const t = useT()
  return (
    <div className="flex items-start justify-between gap-3 border-b border-border px-4 py-2.5">
      <div className="min-w-0">
        <h3 className="flex items-center gap-1.5 text-[13px] font-semibold text-foreground">
          {t(title)}
          {info ? <MetricInfo info={info} /> : null}
        </h3>
        {description ? (
          // Single line so long copy can't make one card's header taller.
          <p className="mt-0.5 truncate text-[11px] text-muted-foreground">
            {t(description)}
          </p>
        ) : null}
      </div>
      {right}
    </div>
  )
}

// ─── Panel ──────────────────────────────────────────────────────────────────

export type BreakdownTab = {
  id: string
  label: string
  dimension: AnalyticsDimension
  /**
   * Consecutive tabs with the same group collapse into one tab with a menu
   * (see BreakdownTabBar), e.g. the UTM parameters under "Campaigns".
   */
  group?: string
  /** Empty-state copy for this tab; falls back to the panel's. */
  emptyLabel?: string
  /**
   * `map` draws the rows as a world choropleth (country dimension only).
   * Map tabs are left out of the "Show more" modal, which lists rows.
   */
  display?: 'list' | 'map'
}

type BreakdownPanelProps = {
  projectId: string
  propertyId: string
  range: AnalyticsRange
  /** Stable id for remembering the selected tab in account prefs. */
  cardId?: string
  title: string
  description?: string
  info?: AnalyticsMetricInfoKey
  tabs: BreakdownTab[]
  /**
   * Show rank numbers in the card. Use this rather than a rank in
   * `renderLeading`: the "Show more" modal always draws its own rank column.
   */
  ranked?: boolean
  /** Leading badge for a row (flag, colour dot). Not for rank numbers. */
  renderLeading?: (
    entry: Models.AnalyticsMetric,
    index: number,
    tabId: string,
  ) => ReactNode
  /** Bar colour for a row; falls back to the neutral accent bar. */
  rowColor?: (entry: Models.AnalyticsMetric, index: number) => string
  /** Display label for a row (e.g. country code to country name). */
  formatLabel?: (value: string, tabId: string) => string
  /** Render values in a monospace face (paths, hostnames). */
  mono?: boolean
  /** Copy shown when the range has no data for this dimension. */
  emptyLabel?: string
  /** External URL for a row (pages, hostnames): adds an open-in-new icon. */
  rowHref?: (value: string, tabId: string) => string | undefined
}

/**
 * Ranked breakdown card with dimension tabs.
 *
 * Only the visible tab's dimension is requested. Switching tabs swaps the
 * dimension on a single query rather than mounting a query per tab, so a panel
 * costs one request at a time and the 21 dimensions never fan out at once.
 */
export function BreakdownPanel({
  projectId,
  propertyId,
  range,
  cardId,
  title,
  description,
  info,
  tabs,
  ranked = false,
  renderLeading,
  rowColor,
  formatLabel,
  mono = false,
  emptyLabel,
  rowHref,
}: BreakdownPanelProps) {
  const t = useT()
  const tabIds = useMemo(() => tabs.map((tab) => tab.id), [tabs])
  // Remembered per card (not per project or property) in account prefs.
  const [activeTab, setActiveTab] = useAnalyticsCardTab(
    cardId,
    tabIds,
    tabs[0]?.id ?? '',
  )
  const [dialogOpen, setDialogOpen] = useState(false)

  const active = tabs.find((tab) => tab.id === activeTab) ?? tabs[0]

  const { filters, addEqualFilter, isFilterActive } = useAnalyticsFilters()
  const filterAttribute = analyticsFilterAttributeForDimension(active.dimension)

  const { breakdown, isLoading, error, unsupported } = useAnalyticsBreakdown(
    projectId,
    propertyId,
    active.dimension,
    range,
    undefined,
    true,
    filters,
  )

  // Unknown rows (no referrer, unparseable agent, unknown location) are
  // dropped, and shares are computed among the known values.
  const { rows, columnTotal, max } = useMemo(() => {
    const known = breakdown.filter((entry) => isKnownBreakdownValue(entry.value))
    return {
      rows: known,
      columnTotal: known.reduce((sum, entry) => sum + entry.visitors, 0),
      max: known.reduce((m, entry) => Math.max(m, entry.visitors), 0),
    }
  }, [breakdown])

  const visible = rows.slice(0, BREAKDOWN_COLLAPSED_ROWS)

  // The modal lists rows, so map tabs are dropped there and "Show more" from
  // the map opens the list tab for the same dimension.
  const listTabs = useMemo(
    () => tabs.filter((tab) => tab.display !== 'map'),
    [tabs],
  )
  const dialogTabId =
    active.display === 'map'
      ? listTabs.find((tab) => tab.dimension === active.dimension)?.id
      : active.id

  // Rows with a filterable dimension and a real value become filter toggles.
  const filterRowProps = (value: string | undefined) => {
    if (!filterAttribute || !value) return {}
    const isActive = isFilterActive(filterAttribute, value)
    return {
      onClick: () => addEqualFilter(filterAttribute, value),
      active: isActive,
      actionTitle: isActive ? t('Remove filter') : t('Filter by this value'),
    }
  }

  return (
    <div className="flex flex-col rounded-lg border border-border bg-card">
      <BreakdownHeader title={title} description={description} info={info} />

      {tabs.length > 1 && (
        <div className="border-b border-border px-4 py-2">
          <BreakdownTabBar
            tabs={tabs}
            value={active.id}
            onValueChange={setActiveTab}
          />
        </div>
      )}

      <BreakdownBody
        // The map shows every country already; the Countries tab has the list.
        fill={active.display === 'map'}
        canShowMore={rows.length > BREAKDOWN_COLLAPSED_ROWS}
        onShowMore={() => setDialogOpen(true)}
      >
        {unsupported ? (
          <BreakdownMessage>
            {t(ANALYTICS_FILTER_UNSUPPORTED_MESSAGE)}
          </BreakdownMessage>
        ) : error ? (
          <BreakdownMessage>
            {error instanceof Error
              ? error.message
              : t('Could not load analytics data')}
          </BreakdownMessage>
        ) : isLoading && rows.length === 0 ? (
          <BreakdownSkeleton />
        ) : rows.length === 0 ? (
          <BreakdownMessage>
            {t(active.emptyLabel ?? emptyLabel ?? 'No data in this range')}
          </BreakdownMessage>
        ) : active.display === 'map' ? (
          <CountryMap
            rows={rows}
            height={BREAKDOWN_BODY_FILL_HEIGHT_PX}
            formatLabel={(code) => formatLabel?.(code, active.id) ?? code}
            onSelect={
              filterAttribute
                ? (code) => addEqualFilter(filterAttribute, code)
                : undefined
            }
            isActive={
              filterAttribute
                ? (code) => isFilterActive(filterAttribute, code)
                : undefined
            }
          />
        ) : (
          <div className="space-y-0.5">
            {visible.map((entry, index) => (
              <AnalyticsValueMenu
                key={`${entry.value}-${index}`}
                dimension={active.dimension}
                value={entry.value!}
                label={formatLabel?.(entry.value!, active.id) ?? entry.value!}
                href={rowHref?.(entry.value!, active.id)}
              >
              {/* Wrapper element: the menu trigger needs a DOM node to attach to. */}
              <div>
              <BreakdownRow
                // Rows are known values only (see isKnownBreakdownValue).
                label={formatLabel?.(entry.value!, active.id) ?? entry.value!}
                value={entry.visitors}
                share={
                  columnTotal > 0 ? (entry.visitors / columnTotal) * 100 : 0
                }
                barPercent={max > 0 ? (entry.visitors / max) * 100 : 0}
                leading={
                  ranked ? (
                    <RowRank index={index} />
                  ) : (
                    renderLeading?.(entry, index, active.id)
                  )
                }
                color={rowColor?.(entry, index)}
                mono={mono}
                href={rowHref?.(entry.value!, active.id)}
                {...filterRowProps(entry.value)}
              />
              </div>
              </AnalyticsValueMenu>
            ))}
          </div>
        )}
      </BreakdownBody>

      {/* Mounted only while open so tab, filter and page count reset. */}
      {dialogOpen ? (
        <BreakdownDialog
          open
          onOpenChange={setDialogOpen}
          projectId={projectId}
          propertyId={propertyId}
          range={range}
          title={title}
          description={description}
          tabs={listTabs}
          initialTabId={dialogTabId}
          renderLeading={renderLeading}
          rowColor={rowColor}
          formatLabel={formatLabel}
          mono={mono}
          emptyLabel={emptyLabel}
          rowHref={rowHref}
        />
      ) : null}
    </div>
  )
}
