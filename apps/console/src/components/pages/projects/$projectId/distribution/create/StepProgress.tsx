import { Check } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { CREATE_APP_STEPS } from './steps'

export function StepProgress({
  currentIndex,
  onSelect,
}: {
  currentIndex: number
  onSelect: (index: number) => void
}) {
  const t = useT()
  const current = CREATE_APP_STEPS[currentIndex]
  return (
    <nav aria-label={t('Steps')}>
      <ol className="flex items-center gap-2">
        {CREATE_APP_STEPS.map((step, index) => {
          const completed = index < currentIndex
          const active = index === currentIndex
          return (
            <li
              key={step.id}
              className={cn(
                'flex min-w-0 items-center gap-2',
                index < CREATE_APP_STEPS.length - 1 && 'flex-1',
              )}
            >
              <button
                type="button"
                disabled={!completed}
                onClick={() => onSelect(index)}
                aria-current={active ? 'step' : undefined}
                className="flex min-w-0 shrink-0 items-center gap-2 text-start disabled:cursor-default"
              >
                <span
                  className={cn(
                    'flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium tabular-nums',
                    completed &&
                      'border-foreground bg-foreground text-background',
                    active && 'border-foreground text-foreground',
                    !completed &&
                      !active &&
                      'border-border text-muted-foreground',
                  )}
                >
                  {completed ? <Check className="h-3.5 w-3.5" /> : index + 1}
                </span>
                <span
                  className={cn(
                    'hidden truncate text-[13px] sm:inline',
                    active
                      ? 'font-medium text-foreground'
                      : 'text-muted-foreground',
                  )}
                >
                  {t(step.label)}
                </span>
              </button>
              {index < CREATE_APP_STEPS.length - 1 && (
                <span className="h-px min-w-4 flex-1 bg-border" />
              )}
            </li>
          )
        })}
      </ol>
      {current && (
        <p className="mt-3 text-[13px] font-medium text-foreground sm:hidden">
          {t(current.label)}
        </p>
      )}
    </nav>
  )
}
