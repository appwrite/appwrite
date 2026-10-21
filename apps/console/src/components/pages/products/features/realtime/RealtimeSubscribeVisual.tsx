import { Radio } from 'lucide-react'
import {
  ArtChip,
  ArtLiveDot,
  ArtPanel,
  ArtToken,
  floatStyle,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'

/** Subscriptions sit on the ring by angle (degrees, clockwise from the end side). */
const SUBSCRIPTIONS = [
  { channel: 'rows', angle: 270 },
  { channel: 'files', angle: 55 },
  { channel: 'presences', angle: 125 },
] as const

function ringPosition(angle: number) {
  const radians = (angle * Math.PI) / 180
  return { left: `${50 + 50 * Math.cos(radians)}%`, top: `${50 + 50 * Math.sin(radians)}%` }
}

export function RealtimeSubscribeVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[420px] w-full max-w-[540px]">
      <div
        className="absolute left-1/2 top-1/2 size-[240px] -translate-x-1/2 -translate-y-1/2 sm:size-[300px]"
        aria-hidden
      >
        <div className="product-hero-orbit absolute inset-0 rounded-full border border-dashed border-[rgb(var(--tone-rgb)/0.45)]" />
        <div className="product-hero-orbit-reverse absolute inset-[22%] rounded-full border border-dashed border-[rgb(var(--tone2-rgb)/0.5)]" />
        <div className="absolute inset-[18%] rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.2),transparent_70%)]" />
      </div>

      <div className="absolute left-1/2 top-1/2 size-[240px] -translate-x-1/2 -translate-y-1/2 sm:size-[300px]">
        {SUBSCRIPTIONS.map((subscription, index) => (
          <span
            key={subscription.channel}
            className="product-hero-rise absolute z-[2] max-w-[180px] -translate-x-1/2 -translate-y-1/2"
            style={riseStyle(480 + index * 120, ringPosition(subscription.angle))}
          >
            <span
              className="product-hero-float inline-flex max-w-full items-center gap-1.5 rounded-full border border-border bg-background px-2 py-1 shadow-sm dark:bg-card"
              style={floatStyle(index * 400)}
            >
              <ArtLiveDot className="size-1.5" />
              <span dir="ltr" className="truncate font-mono text-[10px] text-foreground">
                {subscription.channel}
              </span>
            </span>
          </span>
        ))}
      </div>

      <ArtPanel
        className="absolute left-1/2 top-1/2 z-[1] w-[min(200px,54%)] -translate-x-1/2 -translate-y-1/2"
        innerClassName="product-tone-shadow px-3.5 py-3 text-center"
        delayMs={60}
      >
        <span className="mx-auto flex size-9 items-center justify-center rounded-xl bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]">
          <Radio className="size-4" aria-hidden />
        </span>
        <p className="mt-2 text-[12px] font-semibold text-foreground">{t('Realtime client')}</p>
        <Badge variant="success" className="mt-1.5 text-[10px]">
          {t('1 WebSocket')}
        </Badge>
      </ArtPanel>

      <ArtChip className="start-0 top-0 hidden sm:block" delayMs={200}>
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Add a subscription')}
        </p>
        <p dir="ltr" className="mt-1 whitespace-nowrap font-mono text-[10px]">
          <ArtToken tone="keyword">await</ArtToken> realtime.
          <ArtToken tone="function">subscribe</ArtToken>(
          <ArtToken tone="class">Channel</ArtToken>.<ArtToken tone="function">files</ArtToken>())
        </p>
      </ArtChip>
    </div>
  )
}
