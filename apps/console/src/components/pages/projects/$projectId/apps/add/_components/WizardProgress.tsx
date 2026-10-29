import { Check } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

/** Wizard stages: pick platform - app details & register - connect SDK */
export type WizardStage = 'platform' | 'details' | 'setup'

const STEPS: { id: number; title: string; description: string }[] = [
  {
    id: 1,
    title: 'Choose platform',
    description: 'Pick the client stack you are building.',
  },
  {
    id: 2,
    title: 'App details',
    description: 'Hostname or bundle ID and display name.',
  },
  {
    id: 3,
    title: 'Connect locally',
    description: 'Run a starter or use AI, then verify with a ping.',
  },
]

export function WizardProgress({ stage }: { stage: WizardStage }) {
  const t = useT()
  const activeIndex =
    stage === 'platform' ? 0 : stage === 'details' ? 1 : 2

  return (
    <div className="w-full">
      <nav aria-label={t('Progress')} className="w-full">
        <ol className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          {STEPS.map((s, i) => {
            const isComplete = i < activeIndex
            const isCurrent = i === activeIndex

            return (
              <li
                key={s.id}
                aria-current={isCurrent ? 'step' : undefined}
                className={cn(
                  'flex min-w-0 gap-3 rounded-xl border p-4 transition-colors',
                  isCurrent &&
                    'border-primary/40 bg-card ring-1 ring-primary/15',
                  isComplete &&
                    !isCurrent &&
                    'border-border bg-card/50',
                  !isCurrent &&
                    !isComplete &&
                    'border-border bg-muted/20',
                )}
              >
                <div
                  className={cn(
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[12px] font-semibold transition-colors',
                    isComplete &&
                      'bg-primary text-primary-foreground',
                    isCurrent &&
                      !isComplete &&
                      'bg-background text-primary',
                    !isCurrent &&
                      !isComplete &&
                      'bg-muted/50 text-muted-foreground',
                  )}
                >
                  {isComplete ? (
                    <Check className="h-4 w-4" strokeWidth={2.5} />
                  ) : (
                    s.id
                  )}
                </div>
                <div className="min-w-0 pt-0.5">
                  <p
                    className={cn(
                      'text-[13px] font-semibold',
                      isCurrent || isComplete
                        ? 'text-foreground'
                        : 'text-muted-foreground',
                    )}
                  >
                    {t(s.title)}
                  </p>
                  <p className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
                    {t(s.description)}
                  </p>
                </div>
              </li>
            )
          })}
        </ol>
      </nav>
    </div>
  )
}
