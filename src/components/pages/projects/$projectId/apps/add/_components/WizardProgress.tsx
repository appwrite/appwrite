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
    <div className="w-full bg-muted/20">
      <div className="mx-auto w-full max-w-7xl px-6 py-3.5">
        <div className="flex w-full flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
          {STEPS.map((s, i) => {
          const isComplete = i < activeIndex
          const isCurrent = i === activeIndex

            return (
              <div key={s.id} className="flex min-w-0 flex-1 gap-3">
                <div
                  className={cn(
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 text-[12px] font-semibold transition-colors',
                    isComplete &&
                      'border-primary bg-primary text-primary-foreground',
                    isCurrent &&
                      !isComplete &&
                      'border-primary bg-background text-primary',
                    !isCurrent &&
                      !isComplete &&
                      'border-border bg-muted/50 text-muted-foreground',
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
                      isCurrent ? 'text-foreground' : 'text-muted-foreground',
                    )}
                  >
                    {t(s.title)}
                  </p>
                  <p className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
                    {t(s.description)}
                  </p>
                </div>
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}
