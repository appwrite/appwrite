import { useMemo, useState, type ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'
import type { AnalyticsDimension, Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useT } from '@/lib/i18n/translate'
import {
  useAnalyticsBreakdown,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import { BreakdownRow } from './BreakdownRow'

/** Rows shown before the panel is expanded. */
const COLLAPSED_ROWS = 8

export type BreakdownTab = {
  id: string
  label: string
  dimension: AnalyticsDimension
}

type BreakdownPanelProps = {
  projectId: string
  propertyId: string
  range: AnalyticsRange
  title: string
  description?: string
  tabs: BreakdownTab[]
  /** Leading badge for a row (flag, colour dot, rank). */
  renderLeading?: (
    entry: Models.AnalyticsMetric,
    index: number,
    tabId: string,
  ) => ReactNode
  /** Bar colour for a row; falls back to the neutral accent bar. */
  rowColor?: (entry: Models.AnalyticsMetric, index: number) => string
  /** Render values in a monospace face (paths, hostnames). */
  mono?: boolean
  /** Copy shown when the range has no data for this dimension. */
  emptyLabel?: string
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
  title,
  description,
  tabs,
  renderLeading,
  rowColor,
  mono = false,
  emptyLabel,
}: BreakdownPanelProps) {
  const t = useT()
  const [activeTab, setActiveTab] = useState(tabs[0]?.id ?? '')
  const [expanded, setExpanded] = useState(false)

  const active = tabs.find((tab) => tab.id === activeTab) ?? tabs[0]

  const { breakdown, isLoading, error } = useAnalyticsBreakdown(
    projectId,
    propertyId,
    active.dimension,
    range,
  )

  const { rows, columnTotal, max } = useMemo(() => {
    const total = breakdown.reduce((sum, entry) => sum + entry.visitors, 0)
    return {
      rows: breakdown,
      columnTotal: total,
      max: breakdown.reduce((m, entry) => Math.max(m, entry.visitors), 0),
    }
  }, [breakdown])

  const visible = expanded ? rows : rows.slice(0, COLLAPSED_ROWS)
  const hiddenCount = rows.length - visible.length

  return (
    <div className="rounded-lg border border-border bg-card">
      <div className="border-b border-border px-4 py-2.5">
        <h3 className="text-[13px] font-semibold text-foreground">
          {t(title)}
        </h3>
        {description && (
          <p className="mt-0.5 text-[11px] text-muted-foreground">
            {t(description)}
          </p>
        )}
      </div>

      {tabs.length > 1 && (
        <div className="border-b border-border px-4 py-2">
          <Tabs
            value={active.id}
            onValueChange={(value) => {
              setActiveTab(value)
              setExpanded(false)
            }}
          >
            <TabsList>
              {tabs.map((tab) => (
                <TabsTrigger key={tab.id} value={tab.id}>
                  {t(tab.label)}
                </TabsTrigger>
              ))}
            </TabsList>
          </Tabs>
        </div>
      )}

      <div className="p-4">
        {error ? (
          <p className="py-6 text-center text-[13px] text-muted-foreground">
            {error instanceof Error
              ? error.message
              : t('Could not load analytics data')}
          </p>
        ) : isLoading && rows.length === 0 ? (
          <div className="space-y-1.5 py-1" aria-hidden>
            {Array.from({ length: 4 }).map((_, index) => (
              <div
                key={index}
                className="h-7 animate-pulse rounded-md bg-muted/50"
              />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <p className="py-6 text-center text-[13px] text-muted-foreground">
            {t(emptyLabel ?? 'No data in this range')}
          </p>
        ) : (
          <>
            <div className="space-y-0.5">
              {visible.map((entry, index) => (
                <BreakdownRow
                  key={`${entry.value}-${index}`}
                  // The API returns an empty value when a dimension could not
                  // be derived for those events; that is a real bucket, so it
                  // is labelled rather than hidden.
                  label={entry.value || t('Unknown')}
                  value={entry.visitors}
                  share={
                    columnTotal > 0 ? (entry.visitors / columnTotal) * 100 : 0
                  }
                  barPercent={max > 0 ? (entry.visitors / max) * 100 : 0}
                  leading={renderLeading?.(entry, index, active.id)}
                  color={rowColor?.(entry, index)}
                  mono={mono}
                />
              ))}
            </div>
            {(hiddenCount > 0 || expanded) && (
              <div className="mt-3 flex justify-center">
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 text-[11px]"
                  onClick={() => setExpanded((value) => !value)}
                >
                  {expanded ? (
                    <>
                      {t('Show less')}
                      <ChevronDown className="ms-1 h-3 w-3 rotate-180" />
                    </>
                  ) : (
                    <>
                      {t('Show more')} ({hiddenCount} {t('more')})
                      <ChevronDown className="ms-1 h-3 w-3" />
                    </>
                  )}
                </Button>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
