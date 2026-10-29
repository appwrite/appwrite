import { useMemo, useState } from 'react'
import { AnalyticsDimension, type Models } from '@appwrite.io/console'
import { useT } from '@/lib/i18n/translate'
import {
  isCustomAnalyticsEvent,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import {
  BREAKDOWN_COLLAPSED_ROWS,
  BreakdownBody,
  BreakdownHeader,
  BreakdownMessage,
  BreakdownSkeleton,
  type BreakdownTab,
} from './BreakdownPanel'
import { BreakdownDialog } from './BreakdownDialog'
import { BreakdownRow } from './BreakdownRow'
import { AnalyticsValueMenu } from './AnalyticsValueMenu'
import { useAnalyticsFilters } from './analytics-filters-context'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'
import { Sparkles } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { CustomEventsGuideDialog } from './CustomEventsGuideDialog'

const EVENT_TABS: BreakdownTab[] = [
  { id: 'events', label: 'Event', dimension: AnalyticsDimension.EventName },
]

type EventsPanelProps = {
  projectId: string
  propertyId: string
  range: AnalyticsRange
  /** The eventName breakdown, already fetched by the page. */
  events: Models.AnalyticsMetric[]
  isLoading: boolean
  /** For the guide's code snippets (tracking ID, domain). */
  property: Models.AnalyticsProperty | undefined
  /** Jump to the full integration setup (property Settings). */
  onOpenSetup?: () => void
}

/**
 * Custom events in the range, ranked by count (automatic tracker events are
 * left out). Same fixed card geometry as the dimension panels; the full list
 * opens in the breakdown modal.
 */
export function EventsPanel({
  projectId,
  propertyId,
  range,
  events,
  isLoading,
  property,
  onOpenSetup,
}: EventsPanelProps) {
  const t = useT()
  const [dialogOpen, setDialogOpen] = useState(false)
  const [guideOpen, setGuideOpen] = useState(false)
  const { addEqualFilter, isFilterActive } = useAnalyticsFilters()

  // Custom events only: the tracker's automatic events (pageview, scroll
  // depth, …) are already covered by the metrics and other cards.
  const { sorted, total, max } = useMemo(() => {
    const byCount = events
      .filter(
        (event) =>
          isKnownBreakdownValue(event.value) && isCustomAnalyticsEvent(event.value),
      )
      .sort((a, b) => b.events - a.events)
    return {
      sorted: byCount,
      total: byCount.reduce((sum, event) => sum + event.events, 0),
      max: byCount[0]?.events ?? 0,
    }
  }, [events])

  const visible = sorted.slice(0, BREAKDOWN_COLLAPSED_ROWS)

  return (
    <div className="flex flex-col rounded-lg border border-border bg-card">
      <BreakdownHeader
        title="Custom events"
        description="Events your app tracks, excluding automatic ones"
        info="customEvents"
        right={
          sorted.length > 0 ? (
            <Button
              variant="ghost"
              size="sm"
              className="h-7 shrink-0 px-2 text-[12px] text-muted-foreground"
              onClick={() => setGuideOpen(true)}
            >
              {t('How to track events')}
            </Button>
          ) : undefined
        }
      />
      <BreakdownBody
        canShowMore={sorted.length > BREAKDOWN_COLLAPSED_ROWS}
        onShowMore={() => setDialogOpen(true)}
      >
        {sorted.length === 0 ? (
          isLoading ? (
            <BreakdownSkeleton />
          ) : (
            // Placeholder doubles as onboarding: explain custom events and
            // open the guide.
            <BreakdownMessage>
              <span className="flex max-w-sm flex-col items-center gap-2">
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-muted">
                  <Sparkles className="h-4 w-4 text-muted-foreground" aria-hidden />
                </span>
                <span className="text-[13px] font-medium text-foreground">
                  {t('No custom events yet')}
                </span>
                <span className="text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'Track the actions that matter to your product, like sign-ups or purchases, and see them here.',
                  )}
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  className="mt-1 h-8 text-[12px]"
                  onClick={() => setGuideOpen(true)}
                >
                  {t('Learn how')}
                </Button>
              </span>
            </BreakdownMessage>
          )
        ) : (
          <div className="space-y-0.5">
            {visible.map((event, index) => {
              const row = (
              <BreakdownRow
                label={event.value || t('Unknown')}
                value={event.events}
                share={total > 0 ? (event.events / total) * 100 : 0}
                barPercent={max > 0 ? (event.events / max) * 100 : 0}
                mono
                {...(event.value
                  ? {
                      onClick: () => addEqualFilter('eventName', event.value!),
                      active: isFilterActive('eventName', event.value),
                      actionTitle: isFilterActive('eventName', event.value)
                        ? t('Remove filter')
                        : t('Filter by this event'),
                    }
                  : {})}
              />
              )
              // Same right-click menu as the other cards' values.
              return event.value ? (
                <AnalyticsValueMenu
                  key={`${event.value}-${index}`}
                  dimension={AnalyticsDimension.EventName}
                  value={event.value}
                >
                  <div>{row}</div>
                </AnalyticsValueMenu>
              ) : (
                <div key={`unknown-${index}`}>{row}</div>
              )
            })}
          </div>
        )}
      </BreakdownBody>

      {dialogOpen ? (
        <BreakdownDialog
          open
          onOpenChange={setDialogOpen}
          projectId={projectId}
          propertyId={propertyId}
          range={range}
          title="Custom events"
          description="Events your app tracks, excluding automatic ones"
          tabs={EVENT_TABS}
          valueKey="events"
          mono
          rowFilter={(entry) => isCustomAnalyticsEvent(entry.value)}
          emptyLabel="No custom events in this range"
        />
      ) : null}

      <CustomEventsGuideDialog
        open={guideOpen}
        onOpenChange={setGuideOpen}
        projectId={projectId}
        property={property}
        onOpenSetup={onOpenSetup}
      />
    </div>
  )
}
