import type { CSSProperties } from 'react'
import { Check } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { LanguageIcon } from '@/components/global/shared/LanguageIcon'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { ArtChip, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const OUTER_RUNTIMES = ['python', 'go', 'dart', 'php', 'ruby', 'rust', 'deno', 'dotnet'] as const
const INNER_RUNTIMES = ['bun', 'java', 'swift', 'kotlin', 'cpp'] as const

const PINNED_VERSIONS = [
  { version: 'node-22', active: true },
  { version: 'node-20', active: false },
  { version: 'node-18', active: false },
] as const

function orbitPosition(index: number, total: number, offsetDeg: number): CSSProperties {
  const angle = ((offsetDeg + (360 / total) * index) * Math.PI) / 180
  return {
    left: `${50 + 50 * Math.cos(angle)}%`,
    top: `${50 + 50 * Math.sin(angle)}%`,
  }
}

function OrbitRing({
  runtimes,
  offsetDeg,
  reverse,
  className,
  delayMs,
}: {
  runtimes: readonly string[]
  offsetDeg: number
  reverse?: boolean
  className: string
  delayMs: number
}) {
  return (
    <div
      className={cn(
        'absolute rounded-full border border-dashed border-foreground/15',
        reverse ? 'product-hero-orbit-reverse' : 'product-hero-orbit',
        className,
      )}
      aria-hidden
    >
      {runtimes.map((runtime, index) => (
        <span
          key={runtime}
          className="absolute -translate-x-1/2 -translate-y-1/2"
          style={orbitPosition(index, runtimes.length, offsetDeg)}
        >
          <span
            className="product-hero-rise block"
            style={riseStyle(delayMs + index * 80)}
          >
            <span
              className={cn(
                'flex size-9 items-center justify-center rounded-xl border border-border bg-background shadow-sm dark:bg-card sm:size-11',
                reverse ? 'product-hero-orbit' : 'product-hero-orbit-reverse',
              )}
            >
              <LanguageIcon language={runtime} size="sm" className="size-4 sm:size-5" />
            </span>
          </span>
        </span>
      ))}
    </div>
  )
}

export function FunctionsRuntimesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto aspect-square w-full max-w-[460px]">
      <OrbitRing runtimes={OUTER_RUNTIMES} offsetDeg={-90} className="inset-[6%]" delayMs={250} />
      <OrbitRing
        runtimes={INNER_RUNTIMES}
        offsetDeg={-54}
        reverse
        className="inset-[24%]"
        delayMs={500}
      />
      <div
        className="absolute inset-[34%] rounded-full bg-[radial-gradient(circle,rgb(var(--tone-rgb)/0.22),transparent_70%)]"
        aria-hidden
      />

      <ArtPanel
        className="absolute left-1/2 top-1/2 z-[1] w-[124px] -translate-x-1/2 -translate-y-1/2 sm:w-[150px]"
        innerClassName="product-tone-shadow flex flex-col items-center px-3 py-3 text-center"
        delayMs={60}
      >
        <span className="flex size-10 items-center justify-center rounded-xl border border-[rgb(var(--tone-rgb)/0.45)] bg-muted/30">
          <RuntimeIcon runtime="node-22" size="md" />
        </span>
        <p className="mt-2 text-[12px] font-semibold text-foreground">Node.js</p>
        <span
          dir="ltr"
          className="mt-1 rounded bg-[rgb(var(--tone-rgb)/0.12)] px-1.5 py-0.5 font-mono text-[10px] text-[var(--tone-ink)]"
        >
          node-22
        </span>
      </ArtPanel>

      <ArtChip className="end-0 top-0 hidden w-[150px] sm:block" delayMs={900} floatDelayMs={300}>
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t('Ready to deploy')}
        </p>
        <div dir="ltr" className="mt-1.5 space-y-0.5">
          {PINNED_VERSIONS.map((entry) => (
            <div
              key={entry.version}
              className={cn(
                'flex items-center justify-between rounded px-1.5 py-1 font-mono text-[10px]',
                entry.active ? 'bg-muted/60 text-foreground' : 'text-muted-foreground',
              )}
            >
              {entry.version}
              {entry.active ? (
                <Check className="size-3 text-[var(--tone-ink)]" strokeWidth={3} aria-hidden />
              ) : null}
            </div>
          ))}
        </div>
      </ArtChip>

      <ArtChip className="bottom-0 start-0" delayMs={1100} floatDelayMs={900}>
        <Badge variant="info" className="text-[10px]">
          {t('13+ runtimes')}
        </Badge>
      </ArtChip>

      <ArtChip className="bottom-[4%] end-0 hidden w-[180px] sm:block" delayMs={1250} floatDelayMs={1500}>
        <p className="text-[11px] font-medium text-foreground">{t('Your language, your runtime')}</p>
        <p className="mt-0.5 text-[10px] leading-4 text-muted-foreground">
          {t('Pin your version. Deploy isolated. Built for production.')}
        </p>
      </ArtChip>
    </div>
  )
}
