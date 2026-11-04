import { Loader2 } from 'lucide-react'
import type { ReviewEvent } from '@/lib/appwrite/reviews'
import { useReviewEvents } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type TraceProps = {
  projectId: string
  reviewId: string
  running: boolean
}

/**
 * The steps the review agent took, oldest first, timed from the first step.
 * Updates while the review runs.
 */
export function Trace({ projectId, reviewId, running }: TraceProps) {
  const t = useT()
  const { events, total, isLoading } = useReviewEvents(
    projectId,
    reviewId,
    running,
  )
  const start = events.length > 0 ? Date.parse(events[0].time) : 0

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="flex items-center gap-2 px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Trace')} <span className="text-muted-foreground">{total}</span>
        </h3>
        {running && (
          <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
        )}
      </div>
      <div className="border-t border-border" />
      {isLoading ? (
        <div className="flex justify-center py-6">
          <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
        </div>
      ) : events.length === 0 ? (
        <p className="px-6 py-6 text-[13px] text-muted-foreground">
          {t('No steps recorded yet.')}
        </p>
      ) : (
        <ol className="divide-y divide-border">
          {events.map((event) => (
            <Step key={event.$id} event={event} start={start} />
          ))}
        </ol>
      )}
    </div>
  )
}

function Step({ event, start }: { event: ReviewEvent; start: number }) {
  const failed =
    'error' in event.data ||
    event.type.endsWith('.failed') ||
    event.type.endsWith('.unavailable')
  const details = Object.entries(event.data).filter(
    ([, value]) => value !== null && value !== undefined && value !== '',
  )

  return (
    <li className="flex gap-4 px-6 py-2.5 text-[12px]">
      <span className="w-16 shrink-0 text-end font-mono tabular-nums text-muted-foreground">
        +{formatOffset(Date.parse(event.time) - start)}
      </span>
      <div className="min-w-0 flex-1">
        <span
          className={cn(
            'font-mono',
            failed ? 'text-destructive' : 'text-foreground',
          )}
        >
          {event.type}
        </span>
        {details.length > 0 && (
          <p className="mt-0.5 break-words font-mono text-muted-foreground">
            {details
              .map(([key, value]) => `${key}=${formatValue(value)}`)
              .join('  ')}
          </p>
        )}
      </div>
    </li>
  )
}

function formatOffset(ms: number): string {
  if (!Number.isFinite(ms) || ms < 0) return '0s'
  if (ms < 60_000) return `${(ms / 1000).toFixed(1)}s`
  const minutes = Math.floor(ms / 60_000)
  const seconds = Math.floor((ms % 60_000) / 1000)
  return `${minutes}m${String(seconds).padStart(2, '0')}s`
}

function formatValue(value: unknown): string {
  if (typeof value === 'string') return value
  return JSON.stringify(value)
}
