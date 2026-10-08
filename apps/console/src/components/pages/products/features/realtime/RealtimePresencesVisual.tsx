import {
  ArtChip,
  ArtLiveDot,
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const PRESENCES = [
  { initials: 'PA', email: 'paige@example.com', status: 'editing' },
  { initials: 'WA', email: 'walter@example.com', status: 'online' },
  { initials: 'HA', email: 'happy@example.com', status: 'away' },
] as const

type Presence = (typeof PRESENCES)[number]

function ExpiryRing() {
  const radius = 9
  const circumference = 2 * Math.PI * radius

  return (
    <svg viewBox="0 0 24 24" className="size-7 shrink-0 -rotate-90" aria-hidden>
      <circle cx="12" cy="12" r={radius} fill="none" strokeWidth="2.5" className="stroke-muted" />
      <circle
        cx="12"
        cy="12"
        r={radius}
        fill="none"
        strokeWidth="2.5"
        strokeLinecap="round"
        className="product-art-countdown stroke-[var(--tone-ink)]"
        strokeDasharray={circumference}
        style={{ strokeDashoffset: circumference }}
      />
    </svg>
  )
}

function PresenceRow({ presence, index }: { presence: Presence; index: number }) {
  const t = useT()

  return (
    <li
      className="product-hero-rise flex items-center gap-2.5 rounded-lg border border-border/70 bg-muted/20 px-2.5 py-2"
      style={riseStyle(260 + index * 130)}
    >
      <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-[rgb(var(--tone2-rgb)/0.18)] text-[10px] font-semibold text-foreground">
        {presence.initials}
      </span>
      <span dir="ltr" className="min-w-0 flex-1 truncate text-[11.5px] font-medium text-foreground">
        {presence.email}
      </span>
      <span
        className={cn(
          'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium',
          presence.status === 'away'
            ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
            : 'bg-[rgb(var(--tone-rgb)/0.12)] text-[var(--tone-ink)]',
        )}
      >
        {t(presence.status)}
      </span>
    </li>
  )
}

export function RealtimePresencesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto h-[420px] w-full max-w-[540px]">
      <ArtPanel
        className="absolute start-0 top-[8%] z-[1] w-[min(320px,80%)]"
        innerClassName="product-tone-shadow p-3.5"
        delayMs={60}
      >
        <div className="flex items-center justify-between gap-2">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Who is here')}
          </p>
          <span className="flex items-center gap-1.5">
            <ArtLiveDot className="size-1.5" />
            <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
              presences
            </span>
          </span>
        </div>
        <ul className="mt-2.5 space-y-1.5">
          {PRESENCES.map((presence, index) => (
            <PresenceRow key={presence.email} presence={presence} index={index} />
          ))}
        </ul>
      </ArtPanel>

      <ArtChip className="bottom-[12%] end-0" delayMs={900} floatDelayMs={800}>
        <div className="flex items-center gap-2">
          <ExpiryRing />
          <div>
            <p dir="ltr" className="font-mono text-[10px] text-muted-foreground">
              expiresAt
            </p>
            <p className="text-[11px] font-medium text-foreground">{t('Slides on heartbeat')}</p>
          </div>
        </div>
      </ArtChip>
    </div>
  )
}
