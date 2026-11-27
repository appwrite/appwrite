import {
  BookOpenText,
  Network,
  SquareTerminal,
  type LucideIcon,
} from 'lucide-react'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const STRENGTHS: {
  name: string
  description: string
  credits: string
  icon: LucideIcon
  selected?: boolean
}[] = [
  {
    name: 'Basic',
    description: 'Cheaper models that read the code without running it',
    credits: '10 credits per review',
    icon: BookOpenText,
  },
  {
    name: 'Normal',
    description: 'The default models, running commands in a sandbox',
    credits: '50 credits per review',
    icon: SquareTerminal,
    selected: true,
  },
  {
    name: 'Expert',
    description: 'Stronger models that split the work across subagents',
    credits: '500 credits per review',
    icon: Network,
  },
]

/** The strength picker on a repository: how hard its pull requests are reviewed, and what each review costs. */
export function ReviewsStrengthVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-4 text-start">
      <ArtPanel delayMs={40} innerClassName="product-tone-shadow p-3.5">
        <p className="text-[12.5px] font-semibold text-foreground">
          {t('Review strength')}
        </p>
        <p className="mt-0.5 text-[11px] text-muted-foreground">
          {t(
            'How hard pull requests are reviewed, and what each review costs.',
          )}
        </p>
        <div
          className="mt-3 space-y-2"
          role="radiogroup"
          aria-label={t('Review strength')}
        >
          {STRENGTHS.map((strength, index) => {
            const Icon = strength.icon
            return (
              <div
                key={strength.name}
                className={cn(
                  'product-hero-rise flex items-start gap-3 rounded-lg border px-3 py-2.5',
                  strength.selected
                    ? 'border-[rgb(var(--tone-rgb)/0.6)] bg-[rgb(var(--tone-rgb)/0.08)]'
                    : 'border-border',
                )}
                style={riseStyle(200 + index * 120)}
              >
                <span
                  className={cn(
                    'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border',
                    strength.selected
                      ? 'border-[var(--tone-ink)]'
                      : 'border-muted-foreground/40',
                  )}
                  aria-hidden
                >
                  {strength.selected ? (
                    <span className="size-2 rounded-full bg-[var(--tone-ink)]" />
                  ) : null}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="flex items-center gap-1.5 text-[12px] font-semibold text-foreground">
                    <Icon
                      className="size-3.5 text-muted-foreground"
                      aria-hidden
                    />
                    {t(strength.name)}
                    {strength.selected ? (
                      <span className="rounded-full bg-muted px-1.5 text-[9.5px] font-medium text-muted-foreground">
                        {t('Default')}
                      </span>
                    ) : null}
                  </span>
                  <span className="mt-0.5 block text-[11px] leading-4 text-muted-foreground">
                    {t(strength.description)}
                  </span>
                </span>
                <span className="shrink-0 text-[10.5px] font-medium tabular-nums text-foreground">
                  {t(strength.credits)}
                </span>
              </div>
            )
          })}
        </div>
      </ArtPanel>
    </div>
  )
}
