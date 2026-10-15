import { Ban, Check, KeyRound } from 'lucide-react'
import { ArtConnector, ArtPanel, ArtToken } from '@/components/pages/products/_components/ArtParts'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const SUBSCRIBERS = [
  {
    email: 'paige@example.com',
    outcome: 'Event delivered',
    allowed: true,
  },
  {
    email: 'toby@example.com',
    outcome: 'Nothing delivered',
    allowed: false,
  },
] as const

type Subscriber = (typeof SUBSCRIBERS)[number]

function SubscriberCard({ subscriber, index }: { subscriber: Subscriber; index: number }) {
  const t = useT()
  const Icon = subscriber.allowed ? Check : Ban

  return (
    <ArtPanel
      className="min-w-0"
      innerClassName={cn(
        'h-full px-3 py-3',
        subscriber.allowed
          ? 'product-tone-shadow border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]'
          : 'border-dashed opacity-75 shadow-none',
      )}
      delayMs={620 + index * 160}
    >
      <div className="flex items-center gap-2">
        <span
          className={cn(
            'flex size-7 shrink-0 items-center justify-center rounded-md',
            subscriber.allowed
              ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
              : 'border border-border bg-muted/40 text-muted-foreground',
          )}
        >
          <Icon className="size-3.5" aria-hidden />
        </span>
        <span dir="ltr" className="min-w-0 flex-1 truncate text-[11.5px] font-medium text-foreground">
          {subscriber.email}
        </span>
      </div>
      <Badge variant={subscriber.allowed ? 'success' : 'inactive'} className="mt-2.5 text-[10px]">
        {t(subscriber.outcome)}
      </Badge>
    </ArtPanel>
  )
}

export function RealtimePermissionsVisual() {
  const t = useT()

  return (
    <div className="mx-auto flex h-[420px] w-full max-w-[540px] flex-col justify-center">
      <ArtPanel
        className="mx-auto w-[min(300px,88%)]"
        innerClassName="product-tone-shadow p-3.5 text-center"
        delayMs={60}
      >
        <span className="mx-auto flex size-9 items-center justify-center rounded-xl bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]">
          <KeyRound className="size-4" aria-hidden />
        </span>
        <p className="mt-2 text-[12px] font-semibold text-foreground">{t('Row updated')}</p>
        <p
          dir="ltr"
          className="mt-2 inline-flex rounded-md border border-border bg-muted/30 px-2 py-1 font-mono text-[10px]"
        >
          <ArtToken tone="class">Permission</ArtToken>.
          <ArtToken tone="function">read</ArtToken>(<ArtToken tone="class">Role</ArtToken>.
          <ArtToken tone="function">team</ArtToken>(<ArtToken tone="string">&apos;acme&apos;</ArtToken>
          ))
        </p>
      </ArtPanel>

      <div className="relative mx-auto h-12 w-[min(300px,88%)] shrink-0" aria-hidden>
        <ArtConnector
          orientation="vertical"
          className="absolute left-1/2 top-0 h-6 -translate-x-1/2"
        />
        <div className="absolute top-6 start-[12%] end-[12%] border-t border-dashed border-foreground/20" />
        <ArtConnector
          orientation="vertical"
          className="absolute start-[12%] top-6 h-6 border-[rgb(var(--tone-rgb)/0.6)]"
        />
        <ArtConnector orientation="vertical" className="absolute end-[12%] top-6 h-6" />
      </div>

      <div className="grid grid-cols-2 gap-3">
        {SUBSCRIBERS.map((subscriber, index) => (
          <SubscriberCard key={subscriber.email} subscriber={subscriber} index={index} />
        ))}
      </div>
    </div>
  )
}
