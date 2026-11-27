import { Code2, MessageSquarePlus, MessageSquareText, Plus } from 'lucide-react'
import {
  ArtChip,
  ArtPanel,
} from '@/components/pages/products/_components/ArtParts'
import {
  ANALYTICS_ART_PREVIOUS,
  ANALYTICS_ART_TREND,
  AnalyticsAreaChart,
} from '@/components/pages/products/features/analytics/AnalyticsArtParts'
import { useT } from '@/lib/i18n/translate'

const LAST_INDEX = ANALYTICS_ART_TREND.length - 1

/** Where a chart point sits across the plot, for the overlays. */
function pointLeft(index: number): string {
  return `${(index / LAST_INDEX) * 100}%`
}

/** A note badge hanging its dashed line down the chart, like the console's. */
function NoteMarker({ index, count }: { index: number; count?: number }) {
  return (
    <div
      className="absolute inset-y-0 flex -translate-x-1/2 flex-col items-center"
      style={{ left: pointLeft(index) }}
    >
      <span className="flex h-5 min-w-5 items-center justify-center gap-1 rounded-full border border-foreground/20 bg-background px-1.5 text-[10.5px] font-medium leading-none tabular-nums text-foreground shadow-xs">
        <MessageSquareText className="size-3" aria-hidden />
        {count}
      </span>
      <span className="w-0 flex-1 border-s border-dashed border-foreground/30" />
    </div>
  )
}

/**
 * The overview chart with notes on it: two badges on their dashed lines, the
 * hover chip that adds one, and a note open over the corner.
 */
export function AnalyticsNotesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[520px] pb-24 pt-10 sm:pb-20">
      <ArtChip className="start-0 top-0" delayMs={500} floatDelayMs={0}>
        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <Code2 className="size-3 text-[var(--tone-ink)]" aria-hidden />
          <span dir="ltr" className="font-mono text-[10.5px] text-foreground">
            POST …/annotations
          </span>
          <span className="text-border">·</span>
          {t('From CI')}
        </span>
      </ArtChip>

      <ArtPanel
        delayMs={80}
        innerClassName="product-tone-shadow overflow-hidden p-0"
      >
        <div className="flex items-center justify-between gap-3 border-b border-border px-3.5 py-2.5">
          <div className="flex items-center gap-3 text-[10.5px] text-muted-foreground">
            <span className="flex items-center gap-1.5">
              <span className="size-1.5 rounded-full bg-[var(--tone-ink)]" />
              {t('Visitors')}
            </span>
            <span className="flex items-center gap-1.5">
              <span className="flex size-3.5 items-center justify-center rounded-full border border-foreground/20 text-foreground">
                <MessageSquareText className="size-2" aria-hidden />
              </span>
              {t('Notes')}
            </span>
          </div>
          <span className="flex h-6 items-center gap-1.5 rounded-md border border-border px-2 text-[10.5px] text-muted-foreground">
            <MessageSquarePlus className="size-3" aria-hidden />
            {t('Add note')}
          </span>
        </div>
        <div className="px-3.5 pb-10 pt-3 text-foreground">
          <div dir="ltr" className="relative">
            <AnalyticsAreaChart
              values={ANALYTICS_ART_TREND}
              previous={ANALYTICS_ART_PREVIOUS}
              className="h-[130px]"
              height={130}
            />
            <div className="pointer-events-none absolute inset-0" aria-hidden>
              <NoteMarker index={8} />
              <NoteMarker index={21} count={2} />
              {/* Hover line with the chip that adds a note there. */}
              <div
                className="absolute inset-y-0 flex -translate-x-1/2 flex-col items-center"
                style={{ left: pointLeft(14) }}
              >
                <span className="flex h-5 items-center gap-1 whitespace-nowrap rounded-full bg-foreground pe-2 ps-1.5 text-[10.5px] font-medium leading-none text-background shadow-sm">
                  <Plus className="size-3" strokeWidth={2.5} />
                  {t('Add note')}
                </span>
                <span className="w-px flex-1 bg-foreground/25" />
              </div>
            </div>
          </div>
        </div>
      </ArtPanel>

      {/* The badge's notes, open over the chart's corner. */}
      <ArtPanel
        className="absolute -bottom-1 end-[-4%] w-[min(260px,76%)]"
        delayMs={320}
        float
        floatDelayMs={400}
        innerClassName="divide-y divide-border p-0"
      >
        <div className="space-y-1 px-3 py-2.5">
          <p className="text-[11.5px] text-foreground">
            {t('Launched the new pricing page')}
          </p>
          <p className="text-[10.5px] text-muted-foreground">
            {t('You')} · Nov 21
          </p>
        </div>
        <div className="space-y-1 px-3 py-2.5">
          <p className="text-[11.5px] text-foreground">
            {t('Spring campaign went live')}
          </p>
          <p className="text-[10.5px] text-muted-foreground">Maya · Nov 21</p>
        </div>
      </ArtPanel>
    </div>
  )
}
