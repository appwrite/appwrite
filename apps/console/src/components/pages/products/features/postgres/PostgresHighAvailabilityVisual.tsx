import { ArrowUpFromLine, HeartPulse, Server } from 'lucide-react'
import type { CSSProperties } from 'react'
import {
  ArtChip,
  ArtLiveDot,
  ArtPanel,
  floatStyle,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { PostgresElephantIcon } from '@/components/pages/projects/$projectId/databases/_components/database-mascot-icons'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type Replica = {
  id: string
  /** Angle on the ring, in degrees clockwise from the end side. */
  angle: number
  promoting?: boolean
}

const REPLICAS: Replica[] = [
  { id: 'r1', angle: 300, promoting: true },
  { id: 'r2', angle: 60 },
  { id: 'r3', angle: 180 },
]

function ringPosition(angle: number): CSSProperties {
  const radians = (angle * Math.PI) / 180
  return {
    insetInlineStart: `${50 + 50 * Math.cos(radians)}%`,
    top: `${50 + 50 * Math.sin(radians)}%`,
  }
}

export function PostgresHighAvailabilityVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-4 text-start">
      <div className="relative mx-auto h-[300px] w-full sm:h-[340px]">
        <div
          className="absolute left-1/2 top-1/2 size-[240px] -translate-x-1/2 -translate-y-1/2 sm:size-[280px]"
          aria-hidden
        >
          <div className="absolute inset-0 rounded-full border border-dashed border-foreground/20" />
          <div className="product-hero-orbit absolute inset-[18%] rounded-full border border-dashed border-[rgb(var(--tone-rgb)/0.4)]" />
          <div className="absolute inset-[24%] rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.2),transparent_70%)]" />
        </div>

        <div className="absolute left-1/2 top-1/2 size-[240px] -translate-x-1/2 -translate-y-1/2 sm:size-[280px]">
          {REPLICAS.map((replica, index) => (
            <span
              key={replica.id}
              className="product-hero-rise absolute z-[2] -translate-x-1/2 -translate-y-1/2 rtl:translate-x-1/2"
              style={riseStyle(520 + index * 140, ringPosition(replica.angle))}
            >
              <span
                className={cn(
                  'product-hero-float inline-flex flex-col items-center gap-1 rounded-xl border bg-background px-2.5 py-2 shadow-[0_14px_36px_-20px_rgb(0_0_0/0.4)] dark:bg-card',
                  replica.promoting
                    ? 'border-[rgb(var(--tone-rgb)/0.5)]'
                    : 'border-border',
                )}
                style={floatStyle(index * 500)}
              >
                <span className="flex items-center gap-1">
                  <Server
                    className="size-3 text-muted-foreground"
                    aria-hidden
                  />
                  <span
                    dir="ltr"
                    className="font-mono text-[10.5px] text-foreground"
                  >
                    {replica.id}
                  </span>
                </span>
                {replica.promoting ? (
                  <span className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-md bg-[rgb(var(--tone-rgb)/0.14)] px-1.5 py-0.5 text-[9.5px] font-medium text-[var(--tone-ink)]">
                    <ArrowUpFromLine
                      className="size-2.5 shrink-0"
                      aria-hidden
                    />
                    {t('Next to promote')}
                  </span>
                ) : null}
              </span>
            </span>
          ))}
        </div>

        <ArtPanel
          className="absolute left-1/2 top-1/2 z-[1] w-[min(182px,48%)] -translate-x-1/2 -translate-y-1/2"
          innerClassName="product-tone-shadow px-3 py-3 text-center"
          delayMs={60}
        >
          <span className="mx-auto flex size-9 items-center justify-center rounded-xl bg-[rgb(var(--tone-rgb)/0.14)] text-[var(--tone-ink)]">
            <PostgresElephantIcon className="size-5" aria-hidden />
          </span>
          <p className="mt-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Primary')}
          </p>
          <p
            dir="ltr"
            className="mt-0.5 font-mono text-[11.5px] text-foreground"
          >
            db-7f3a2c.fra
          </p>
          <p className="mt-1 inline-flex items-center gap-1.5 text-[10px] text-muted-foreground">
            <ArtLiveDot className="size-1.5" />
            {t('Streaming to 3 replicas')}
          </p>
        </ArtPanel>
      </div>

      <ArtChip className="start-0 top-0" delayMs={900} floatDelayMs={600}>
        <div className="flex items-center gap-2">
          <HeartPulse
            className="size-3.5 text-emerald-600 dark:text-emerald-400"
            aria-hidden
          />
          <div>
            <p className="text-[11px] font-medium text-foreground">
              {t('Automatic failover')}
            </p>
            <p className="text-[10px] text-muted-foreground">
              {t('Same hostname, same port')}
            </p>
          </div>
        </div>
      </ArtChip>
    </div>
  )
}
