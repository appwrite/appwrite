import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export function ChoiceCard({
  selected,
  icon,
  title,
  description,
  disabled = false,
  onSelect,
}: {
  selected: boolean
  icon: ReactNode
  title: string
  description: string
  disabled?: boolean
  onSelect: () => void
}) {
  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      onClick={onSelect}
      disabled={disabled}
      className={cn(
        'flex w-full cursor-pointer items-start gap-3 rounded-xl border border-border bg-card/50 p-3.5 text-start transition-all hover:border-border/80 hover:bg-card/60 disabled:cursor-not-allowed disabled:opacity-60',
        selected &&
          'border-primary ring-1 ring-primary/20 hover:border-primary',
      )}
    >
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
        {icon}
      </div>
      <div className="min-w-0">
        <p className="text-[13px] font-medium text-foreground">{title}</p>
        <p className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
          {description}
        </p>
      </div>
    </button>
  )
}
