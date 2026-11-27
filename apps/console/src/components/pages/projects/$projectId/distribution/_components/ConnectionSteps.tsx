import { Check } from 'lucide-react'
import { cn } from '@/lib/utils'

interface ConnectionStepsProps {
  steps: string[]
  current: number
}

export function ConnectionSteps({ steps, current }: ConnectionStepsProps) {
  return (
    <ol className="flex flex-wrap items-center gap-x-4 gap-y-2 px-6 pb-4">
      {steps.map((step, index) => {
        const done = index < current
        const active = index === current
        return (
          <li
            key={step}
            className="flex items-center gap-2"
            aria-current={active ? 'step' : undefined}
          >
            <span
              className={cn(
                'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-[11px] font-medium',
                active && 'border-foreground bg-foreground text-background',
                done && 'border-border bg-muted text-foreground',
                !active && !done && 'border-border text-muted-foreground',
              )}
            >
              {done ? <Check className="h-3 w-3" /> : index + 1}
            </span>
            <span
              className={cn(
                'text-[12px]',
                active
                  ? 'font-medium text-foreground'
                  : 'text-muted-foreground',
              )}
            >
              {step}
            </span>
          </li>
        )
      })}
    </ol>
  )
}
