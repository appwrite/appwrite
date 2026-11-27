import { Clock, Repeat, User, Users } from 'lucide-react'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import {
  MonitorsCaption,
  MonitorsField,
} from '@/components/pages/products/features/monitors/MonitorsArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const ESCALATIONS = [
  {
    name: 'On call',
    summary: 'Developers now → Ana, Ben after 10 m · repeats 2×',
    used: 'Used by 4 monitors',
    active: true,
  },
  {
    name: 'Billing',
    summary: 'Owners now',
    used: 'Used by 1 monitor',
    active: false,
  },
] as const

const STEPS = [
  { wait: 'Right away', who: [{ name: 'Developers', role: true }] },
  {
    wait: 'After 10 minutes',
    who: [
      { name: 'Ana Silva', role: false },
      { name: 'Ben Okafor', role: false },
    ],
  },
] as const

/** Organization settings → Escalations: the list, and the steps of the one most monitors use. */
export function MonitorsEscalationVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[560px] py-6">
      <ArtPanel delayMs={40} innerClassName="product-tone-shadow p-0">
        <div className="px-4 py-3">
          <p className="text-[13px] font-semibold text-foreground">
            {t('Escalations')}
          </p>
          <p className="mt-1 text-[11px] leading-[16px] text-muted-foreground">
            {t(
              'Notify your team step by step until someone acknowledges the alert. Pick an escalation on any monitor.',
            )}
          </p>
        </div>
        <ul className="divide-y divide-border/70 border-t border-border">
          {ESCALATIONS.map((escalation, index) => (
            <li
              key={escalation.name}
              className={cn(
                'product-hero-rise flex items-center gap-3 px-4 py-2.5',
                escalation.active && 'bg-[rgb(var(--tone-rgb)/0.06)]',
              )}
              style={riseStyle(200 + index * 100)}
            >
              <span className="min-w-0 flex-1">
                <span className="block text-[12px] font-medium text-foreground">
                  {t(escalation.name)}
                </span>
                <span className="block truncate text-[10.5px] text-muted-foreground">
                  {t(escalation.summary)}
                </span>
              </span>
              <span className="shrink-0 text-[10px] text-muted-foreground">
                {t(escalation.used)}
              </span>
            </li>
          ))}
        </ul>
      </ArtPanel>

      <ArtPanel
        className="relative mt-3 sm:-me-[4%] sm:ms-auto sm:w-[340px]"
        delayMs={500}
        float
        floatDelayMs={300}
        innerClassName="p-3.5"
      >
        <MonitorsCaption>{t('On call')}</MonitorsCaption>
        <p className="mt-1 text-[10.5px] text-muted-foreground">
          {t(
            'Steps are notified in turn until someone acknowledges the alert.',
          )}
        </p>
        <ol className="mt-3 space-y-2">
          {STEPS.map((step, index) => (
            <li
              key={step.wait}
              className="product-hero-rise rounded-lg border border-border bg-background px-3 py-2 dark:bg-card"
              style={riseStyle(700 + index * 140)}
            >
              <p className="flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                <span className="flex size-4 items-center justify-center rounded-full bg-[rgb(var(--tone-rgb)/0.14)] font-mono text-[9px] text-[var(--tone-ink)]">
                  {index + 1}
                </span>
                <Clock className="size-3" aria-hidden />
                {t(step.wait)}
              </p>
              <div className="mt-1.5 flex flex-wrap gap-1">
                {step.who.map((person) => {
                  const Icon = person.role ? Users : User
                  return (
                    <span
                      key={person.name}
                      className="inline-flex items-center gap-1 rounded-full border border-border bg-muted/40 px-2 py-0.5 text-[10.5px] text-foreground"
                    >
                      <Icon
                        className="size-3 text-muted-foreground"
                        aria-hidden
                      />
                      {person.role ? t(person.name) : person.name}
                    </span>
                  )
                })}
              </div>
            </li>
          ))}
        </ol>
        <p className="mt-3 flex flex-wrap items-center gap-1.5 text-[11px] text-foreground">
          <Repeat className="size-3.5 text-muted-foreground" aria-hidden />
          {t('Repeat')}
          <MonitorsField select value={t('2 times')} />
        </p>
        <p className="mt-1.5 text-[10px] leading-[14px] text-muted-foreground">
          {t(
            'Starts again from the first step, a monitor’s cooldown after the last one.',
          )}
        </p>
      </ArtPanel>
    </div>
  )
}
