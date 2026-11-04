import { ChevronRight, Loader2 } from 'lucide-react'
import { useMemo } from 'react'
import { Badge } from '@/components/ui/badge'
import { useReviewEvents } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import {
  buildTrace,
  formatMs,
  formatOffset,
  type TraceItem,
  type TraceSubagent,
  type TraceTone,
} from '@/lib/review-trace'
import { cn } from '@/lib/utils'

type TraceProps = {
  projectId: string
  reviewId: string
  running: boolean
}

const tones: Record<TraceTone, string> = {
  default: 'text-muted-foreground',
  ok: 'text-emerald-600 dark:text-emerald-400',
  warn: 'text-amber-600 dark:text-amber-400',
  error: 'text-red-600 dark:text-red-400',
  muted: 'text-muted-foreground/60',
}

const summaryRow =
  'flex cursor-pointer list-none items-baseline gap-3 px-6 py-2 hover:bg-muted/40 [&::-webkit-details-marker]:hidden'

/**
 * The steps the review agent took, oldest first, timed from the first step.
 * Each row opens to the step's raw data; a subagent's steps fold under the
 * row that started it. Updates while the review runs.
 */
export function Trace({ projectId, reviewId, running }: TraceProps) {
  const t = useT()
  const { events, total, isLoading } = useReviewEvents(
    projectId,
    reviewId,
    running,
  )
  const entries = useMemo(() => buildTrace(t, events), [t, events])

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
      ) : entries.length === 0 ? (
        <p className="px-6 py-6 text-[13px] text-muted-foreground">
          {t('No steps recorded yet.')}
        </p>
      ) : (
        <ol className="divide-y divide-border text-[13px]">
          {entries.map((entry) => (
            <li key={entry.kind === 'event' ? entry.item.id : entry.start.id}>
              {entry.kind === 'event' ? (
                <Step item={entry.item} />
              ) : (
                <Subagent group={entry} />
              )}
            </li>
          ))}
        </ol>
      )}
    </div>
  )
}

function Offset({ seconds }: { seconds: number }) {
  return (
    <span className="hidden w-12 shrink-0 text-end font-mono text-[12px] tabular-nums text-muted-foreground/70 sm:block">
      {formatOffset(seconds)}
    </span>
  )
}

function Pills({ children }: { children: React.ReactNode }) {
  return <span className="hidden shrink-0 gap-1 sm:flex">{children}</span>
}

function Step({ item }: { item: TraceItem }) {
  return (
    <details className="group">
      <summary className={summaryRow}>
        <Offset seconds={item.offset} />
        <span
          className={cn(
            'w-24 shrink-0 text-[12px] font-medium sm:w-32',
            tones[item.tone],
          )}
        >
          {item.label}
        </span>
        <span
          className={cn(
            'min-w-0 flex-1 truncate text-foreground group-open:whitespace-normal group-open:break-all',
            item.mono && 'font-mono text-[12px]',
          )}
        >
          {item.summary}
        </span>
        {item.meta.length > 0 && (
          <Pills>
            {item.meta.map((fact) => (
              <Badge key={fact} variant="outline">
                {fact}
              </Badge>
            ))}
          </Pills>
        )}
      </summary>
      <div className="space-y-2 px-6 pb-3 sm:ps-[13.5rem]">
        {item.code && (
          <pre className="overflow-x-auto whitespace-pre-wrap rounded-md bg-muted/60 p-3 font-mono text-[12px] text-foreground">
            {item.code}
          </pre>
        )}
        <pre className="overflow-x-auto whitespace-pre-wrap break-all rounded-md bg-muted/60 p-3 font-mono text-[12px] text-muted-foreground">
          {JSON.stringify(item.data, null, 2)}
        </pre>
      </div>
    </details>
  )
}

function Subagent({ group }: { group: TraceSubagent }) {
  const t = useT()
  const { end } = group
  const steps = group.items.length

  return (
    <details className="group/agent">
      <summary className={summaryRow}>
        <Offset seconds={group.start.offset} />
        <span className="flex w-24 shrink-0 items-center gap-1 text-[12px] font-medium text-blue-600 sm:w-32 dark:text-blue-400">
          <ChevronRight className="h-3 w-3 shrink-0 transition-transform group-open/agent:rotate-90" />
          <span className="truncate">
            {t('Subagent {agent}').replace('{agent}', group.agent)}
          </span>
        </span>
        <span className="min-w-0 flex-1">
          <span className="line-clamp-2 text-foreground group-open/agent:line-clamp-none">
            {group.task}
          </span>
          {group.paths.length > 0 && (
            <span className="mt-1 block truncate font-mono text-[12px] text-muted-foreground">
              {group.paths.join(', ')}
            </span>
          )}
        </span>
        <Pills>
          <Badge variant="outline">
            {t(steps === 1 ? '{count} step' : '{count} steps').replace(
              '{count}',
              String(steps),
            )}
          </Badge>
          {end ? (
            <>
              <Badge
                variant={end.type === 'subagent.failed' ? 'failed' : 'outline'}
                className={cn(
                  end.type !== 'subagent.failed' && tones[end.tone],
                )}
              >
                {end.type === 'subagent.failed' ? t('failed') : end.summary}
              </Badge>
              {typeof end.data.ms === 'number' && (
                <Badge variant="outline">{formatMs(end.data.ms)}</Badge>
              )}
            </>
          ) : (
            <Badge variant="processing">{t('running')}</Badge>
          )}
        </Pills>
      </summary>
      <ol className="ms-2 divide-y divide-border border-s-2 border-blue-500/30 sm:ms-[4.75rem]">
        {group.items.map((item) => (
          <li key={item.id}>
            <Step item={item} />
          </li>
        ))}
        {end && (
          <li>
            <Step item={end} />
          </li>
        )}
      </ol>
    </details>
  )
}
