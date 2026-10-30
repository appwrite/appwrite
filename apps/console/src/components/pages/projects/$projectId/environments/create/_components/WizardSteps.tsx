import { Check } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export interface WizardStep {
  id: string
  /** Wrapped in t() where it renders */
  title: string
}

/** The steps of the wizard in a row, the current one marked and the done ones ticked. */
export function WizardSteps({
  steps,
  current,
}: {
  steps: readonly WizardStep[]
  current: string
}) {
  const t = useT()
  const activeIndex = steps.findIndex((step) => step.id === current)

  return (
    <nav aria-label={t('Progress')}>
      <ol className="flex flex-wrap items-center gap-x-2 gap-y-2">
        {steps.map((step, index) => {
          const isComplete = index < activeIndex
          const isCurrent = index === activeIndex
          return (
            <li
              key={step.id}
              aria-current={isCurrent ? 'step' : undefined}
              className="flex items-center gap-2"
            >
              <span
                className={cn(
                  'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold transition-colors',
                  isComplete && 'bg-primary text-primary-foreground',
                  isCurrent && 'border border-primary text-primary',
                  !isComplete &&
                    !isCurrent &&
                    'border border-border text-muted-foreground',
                )}
              >
                {isComplete ? (
                  <Check className="h-3.5 w-3.5" strokeWidth={2.5} />
                ) : (
                  index + 1
                )}
              </span>
              <span
                className={cn(
                  'text-[13px]',
                  isCurrent
                    ? 'font-semibold text-foreground'
                    : 'text-muted-foreground',
                )}
              >
                {t(step.title)}
              </span>
              {index < steps.length - 1 && (
                <span aria-hidden className="mx-1 h-px w-6 bg-border" />
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
