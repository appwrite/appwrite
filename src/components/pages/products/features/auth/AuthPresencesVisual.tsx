import { Check, MessageSquare } from 'lucide-react'
import type { CSSProperties } from 'react'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { ArtChip, ArtLiveDot, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const COLLABORATORS = [
  { name: 'Happy Quinn', color: 'var(--brand-cta)' },
  { name: 'Paige Dineen', color: '#7C67FE' },
  { name: 'Toby Curtis', color: '#85DBD8' },
] as const

function PeerCursor({
  name,
  color,
  className,
  wander,
}: {
  name: string
  color: string
  className: string
  wander: CSSProperties
}) {
  return (
    <div className={cn('pointer-events-none absolute z-10', className)} aria-hidden>
      <div className="product-art-wander flex items-center gap-1" style={wander}>
        <svg width="17" height="20" viewBox="0 0 24 28" className="shrink-0 drop-shadow-[0_1px_4px_rgba(0,0,0,0.22)]">
          <path
            d="M4 2.5v19.8c0 .55.66.82 1.04.43l5.9-5.7a.6.6 0 0 1 .42-.17h8.2c.55 0 .82-.66.43-1.04L5.47 2.07A.6.6 0 0 0 4 2.5Z"
            fill={color}
            stroke="white"
            strokeWidth="1.75"
            strokeLinejoin="round"
          />
        </svg>
        <span
          className="rounded-full px-2 py-0.5 text-[9px] font-semibold leading-none text-white shadow-[0_1px_4px_rgba(0,0,0,0.18)]"
          style={{ backgroundColor: color }}
        >
          {name.split(' ')[0]}
        </span>
      </div>
    </div>
  )
}

export function AuthPresencesVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[520px] px-2 pb-16 pt-14 sm:px-8">
      <div
        className="product-hero-rise product-tone-shadow relative rounded-xl border border-border bg-background px-5 py-5 dark:bg-card"
        style={riseStyle(60)}
      >
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{t('Shared doc')}</p>
        <p className="mt-1 text-[15px] font-semibold text-foreground">{t('Q4 launch plan')}</p>
        <div className="mt-4 space-y-2" aria-hidden>
          <div className="h-1.5 w-full rounded-full bg-muted" />
          <div className="h-1.5 w-11/12 rounded-full bg-muted" />
          <div className="h-1.5 w-3/5 rounded-full bg-muted" />
        </div>
        <div className="mt-5 space-y-2">
          <div className="flex items-center gap-2">
            <span className="flex size-4 items-center justify-center rounded border border-emerald-500/50 bg-emerald-500/15">
              <Check className="size-2.5 text-emerald-600 dark:text-emerald-400" aria-hidden />
            </span>
            <span className="text-[12px] text-muted-foreground line-through">{t('Finalize hero copy')}</span>
          </div>
          <div className="flex items-center gap-2">
            <span className="size-4 rounded border border-border bg-background" aria-hidden />
            <span className="text-[12px] text-foreground">{t('Review pricing section')}</span>
            <span className="h-3.5 w-px animate-[ai-mock-cursor-blink_1s_step-end_infinite] bg-[#7C67FE] motion-reduce:animate-none" aria-hidden />
          </div>
        </div>

        <PeerCursor
          name={COLLABORATORS[0].name}
          color={COLLABORATORS[0].color}
          className="end-[18%] top-[22%]"
          wander={{ '--wander-x': '-34px', '--wander-y': '12px', '--wander-duration': '8s' } as CSSProperties}
        />
        <PeerCursor
          name={COLLABORATORS[1].name}
          color={COLLABORATORS[1].color}
          className="start-[52%] top-[70%]"
          wander={{ '--wander-x': '22px', '--wander-y': '-8px', '--wander-duration': '6.5s', '--wander-delay': '800ms' } as CSSProperties}
        />
      </div>

      <ArtChip className="end-0 top-0" delayMs={500}>
        <div className="flex items-center gap-2.5">
          <div className="flex -space-x-1.5 rtl:space-x-reverse">
            {COLLABORATORS.map((peer) => (
              <InitialsAvatar key={peer.name} name={peer.name} size="xs" className="ring-2 ring-background" />
            ))}
          </div>
          <span className="flex items-center gap-1.5 text-[11px] font-medium text-foreground">
            <ArtLiveDot />3 {t('online')}
          </span>
        </div>
      </ArtChip>

      <ArtChip className="bottom-1 start-0" delayMs={800} floatDelayMs={800}>
        <div className="flex items-center gap-2">
          <MessageSquare className="size-3.5 shrink-0 text-[var(--tone-ink)]" aria-hidden />
          <p className="text-[11px] text-muted-foreground">
            <span className="font-medium text-foreground">Happy Quinn</span> {t('is typing in team chat')}
          </p>
          <span className="flex gap-0.5" aria-hidden>
            {[0, 1, 2].map((dot) => (
              <span
                key={dot}
                className="size-1 animate-bounce rounded-full bg-muted-foreground/60 motion-reduce:animate-none"
                style={{ animationDelay: `${dot * 120}ms` }}
              />
            ))}
          </span>
        </div>
      </ArtChip>

      <ArtChip className="end-[4%] bottom-[18%] hidden sm:block" delayMs={1100} floatDelayMs={1500}>
        <p className="text-[10px] text-muted-foreground">
          <span className="font-medium text-foreground">Paige</span> {t('viewing')}{' '}
          <span dir="ltr" className="font-mono">/teams/acme/roadmap</span>
        </p>
      </ArtChip>
    </div>
  )
}
