import { ArrowDownLeft, ArrowUpRight, Check } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STEPS = [
  {
    title: 'Connect to Realtime',
    description: 'Act as a guest or a project user, then press Connect.',
  },
  {
    title: 'Subscribe to a channel',
    description: 'Pick channels such as rows, files, or account events.',
  },
  {
    title: 'Watch frames arrive',
    description: 'Every event and heartbeat is logged here with its payload.',
  },
]

const FRAMES = [
  {
    direction: 'in',
    type: 'event',
    channel: 'databases.*.tables.*.rows.*.create',
  },
  { direction: 'out', type: 'subscribe', channel: 'files' },
  { direction: 'in', type: 'pong', channel: 'heartbeat' },
] as const

/** Faded preview of the frames the log will show once traffic flows. */
function FramePreview({ live }: { live: boolean }) {
  return (
    <div
      aria-hidden
      dir="ltr"
      className="relative overflow-hidden rounded-lg border border-border bg-card/60"
      style={{
        maskImage: 'linear-gradient(to bottom, black 40%, transparent)',
        WebkitMaskImage: 'linear-gradient(to bottom, black 40%, transparent)',
      }}
    >
      {FRAMES.map((frame) => {
        const Icon = frame.direction === 'in' ? ArrowDownLeft : ArrowUpRight
        return (
          <div
            key={frame.type}
            className="flex items-center gap-2 border-b border-border px-3 py-2 font-mono text-[10px] last:border-b-0"
          >
            <Icon
              className={cn(
                'h-3 w-3 shrink-0',
                frame.direction === 'in'
                  ? 'text-sky-600 dark:text-sky-400'
                  : 'text-emerald-600 dark:text-emerald-400',
              )}
            />
            <span className="w-14 shrink-0 text-foreground">{frame.type}</span>
            <span className="truncate text-muted-foreground">
              {frame.channel}
            </span>
          </div>
        )
      })}
      {live ? (
        <span className="absolute end-3 top-2.5 h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500" />
      ) : null}
    </div>
  )
}

export function MessagesEmptyState({
  isConnected,
  hasSubscriptions,
}: {
  isConnected: boolean
  hasSubscriptions: boolean
}) {
  const t = useT()
  const currentStep = !isConnected ? 0 : !hasSubscriptions ? 1 : 2
  const copy = [
    {
      title: 'No messages yet', // pragma: allowlist secret
      description:
        'Connect as guest or a project user, then subscribe to channels to inspect WebSocket traffic. You can also insert sample frames to preview payload structure.',
    },
    {
      title: 'Waiting for subscriptions',
      description:
        'Add a channel subscription to start receiving and logging Realtime frames.',
    },
    {
      title: 'Listening for traffic',
      description:
        'Incoming and outgoing WebSocket frames will appear here as they arrive.',
    },
  ][currentStep]

  return (
    <div className="w-full">
      <FramePreview live={currentStep === 2} />
      <h3 className="mt-5 text-center text-[15px] font-medium text-foreground">
        {t(copy.title)}
      </h3>
      <p className="mt-1.5 text-center text-[13px] leading-relaxed text-muted-foreground">
        {t(copy.description)}
      </p>
      <ol className="mt-5 overflow-hidden rounded-lg border border-border bg-card/50">
        {STEPS.map((step, index) => {
          const done = index < currentStep
          const current = index === currentStep
          return (
            <li
              key={step.title}
              className={cn(
                'flex items-start gap-3 px-3.5 py-3',
                index > 0 && 'border-t border-border',
                current && 'bg-muted/40',
              )}
            >
              <span
                className={cn(
                  'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border font-mono text-[10px] tabular-nums',
                  done &&
                    'border-emerald-500/40 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                  current &&
                    'border-[color-mix(in_oklch,var(--brand-cta)_55%,transparent)] text-[var(--brand-cta)]',
                  !done && !current && 'border-border text-muted-foreground',
                )}
              >
                {done ? <Check className="h-3 w-3" /> : index + 1}
              </span>
              <span className="min-w-0 text-start">
                <span
                  className={cn(
                    'block text-[13px] font-medium',
                    done ? 'text-muted-foreground' : 'text-foreground',
                  )}
                >
                  {t(step.title)}
                </span>
                <span className="mt-0.5 block text-[12px] leading-relaxed text-muted-foreground">
                  {t(step.description)}
                </span>
              </span>
            </li>
          )
        })}
      </ol>
    </div>
  )
}
