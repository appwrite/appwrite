import { Check, ChevronLeft, Clock, Repeat, User, Users } from 'lucide-react'
import {
  ArtChip,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  BeepCard,
  BeepPhone,
} from '@/components/pages/products/features/beep/BeepArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type Step = {
  wait: string
  who: string
  person: boolean
  state: 'notified' | 'next' | 'waiting'
  status: string
}

const STEPS: Step[] = [
  {
    wait: 'Right away',
    who: 'Owners',
    person: false,
    state: 'notified',
    status: 'Notified at 9:37',
  },
  {
    wait: 'After 10 min',
    who: 'Developers',
    person: false,
    state: 'next',
    status: 'Next, in 6 min',
  },
  {
    wait: 'After 30 min',
    who: 'Ana Silva',
    person: true,
    state: 'waiting',
    status: 'Waiting',
  },
]

/** An alert's escalation on the phone: who was paged, who is next, and the button that stops it. */
export function BeepEscalationVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto flex w-full max-w-[540px] justify-center py-4">
      <div className="product-hero-rise" style={riseStyle(40)}>
        <BeepPhone>
          <div className="flex items-center px-3.5 pt-1">
            <span className="flex items-center gap-0.5 rounded-full border border-border bg-background px-2 py-1 text-[10px] font-medium text-foreground dark:bg-card">
              <ChevronLeft className="size-3" aria-hidden />
              {t('Egress spike')}
            </span>
          </div>
          <div className="px-3.5 pt-3">
            <p className="font-aeonik-pro text-[23px] leading-none tracking-tight text-foreground">
              {t('Escalation')}
            </p>
            <p className="mt-1.5 text-[10px] leading-[14px] text-muted-foreground">
              {t(
                'Who an alert notifies, step by step, until someone acknowledges it or it resolves.',
              )}
            </p>
          </div>
          <div className="mt-3 px-3.5">
            <BeepCard className="px-0 py-1">
              <ol>
                {STEPS.map((step, index) => {
                  const Icon = step.person ? User : Users
                  return (
                    <li
                      key={step.who}
                      className="product-hero-rise relative flex gap-2.5 px-3 py-2.5"
                      style={riseStyle(260 + index * 160)}
                    >
                      {index < STEPS.length - 1 ? (
                        <span
                          className="absolute start-[22px] top-[34px] h-[calc(100%-22px)] w-px bg-border"
                          aria-hidden
                        />
                      ) : null}
                      <span
                        className={cn(
                          'relative flex size-5 shrink-0 items-center justify-center rounded-full',
                          step.state === 'notified' &&
                            'bg-emerald-500 text-white',
                          step.state === 'next' &&
                            'border border-[var(--tone-ink)] bg-background text-[var(--tone-ink)] dark:bg-card',
                          step.state === 'waiting' &&
                            'border border-border bg-background text-muted-foreground dark:bg-card',
                        )}
                      >
                        {step.state === 'notified' ? (
                          <Check
                            className="size-3"
                            strokeWidth={3}
                            aria-hidden
                          />
                        ) : (
                          <span className="text-[9px] font-semibold tabular-nums">
                            {index + 1}
                          </span>
                        )}
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="flex items-center gap-1 text-[9px] font-medium uppercase tracking-wider text-muted-foreground">
                          <Clock className="size-2.5" aria-hidden />
                          {t(step.wait)}
                        </span>
                        <span className="mt-0.5 flex items-center gap-1.5 text-[11px] font-semibold text-foreground">
                          <Icon
                            className="size-3 text-muted-foreground"
                            aria-hidden
                          />
                          {t(step.who)}
                        </span>
                        <span
                          className={cn(
                            'mt-0.5 block text-[9.5px]',
                            step.state === 'notified' &&
                              'text-emerald-600 dark:text-emerald-400',
                            step.state === 'next' && 'text-[var(--tone-ink)]',
                            step.state === 'waiting' && 'text-muted-foreground',
                          )}
                        >
                          {t(step.status)}
                        </span>
                      </span>
                    </li>
                  )
                })}
              </ol>
              <p className="flex items-center gap-1.5 border-t border-border/70 px-3 py-2 text-[9.5px] text-muted-foreground">
                <Repeat className="size-3" aria-hidden />
                {t('Then repeats 2 more times')}
              </p>
            </BeepCard>
            <span className="mt-3 flex items-center justify-center gap-1.5 rounded-xl bg-foreground py-2 text-[11px] font-semibold text-background">
              <Check className="size-3.5" aria-hidden />
              {t('Acknowledge')}
            </span>
            <p className="mt-1.5 text-center text-[9px] text-muted-foreground">
              {t('Your team sees that you have it, and the escalation stops.')}
            </p>
          </div>
        </BeepPhone>
      </div>

      <ArtChip
        className="bottom-[12%] end-0 hidden sm:block"
        delayMs={1000}
        floatDelayMs={300}
      >
        <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
          <span
            className="size-1.5 rounded-full bg-[var(--tone-ink)]"
            aria-hidden
          />
          {t('Create and edit escalations on the phone')}
        </span>
      </ArtChip>
    </div>
  )
}
