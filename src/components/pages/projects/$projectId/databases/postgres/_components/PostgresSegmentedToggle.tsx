import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { cn } from '@/lib/utils'
import { POSTGRES_SEGMENTED_TOGGLE_ITEM_CLASS } from './postgres-chrome'

type PostgresSegmentedToggleOption<T extends string> = {
  value: T
  label: string
}

type PostgresSegmentedToggleProps<T extends string> = {
  value: T
  onValueChange: (value: T) => void
  options: PostgresSegmentedToggleOption<T>[]
  ariaLabel: string
  /** Full-width header bar (sidebar Data / Queries / History). */
  variant?: 'bar' | 'inline'
  className?: string
}

export function PostgresSegmentedToggle<T extends string>({
  value,
  onValueChange,
  options,
  ariaLabel,
  variant = 'inline',
  className,
}: PostgresSegmentedToggleProps<T>) {
  return (
    <div
      className={cn(
        variant === 'bar'
          ? 'flex h-12 w-full shrink-0 items-center border-b border-border bg-muted/20 px-2'
          : 'shrink-0',
        className,
      )}
    >
      <ToggleGroup
        type="single"
        variant="outline"
        size="sm"
        value={value}
        onValueChange={(next) => {
          if (!next) return
          if (options.some((option) => option.value === next)) {
            onValueChange(next as T)
          }
        }}
        className={cn(variant === 'bar' ? 'w-full' : 'shrink-0')}
        aria-label={ariaLabel}
      >
        {options.map((option) => (
          <ToggleGroupItem
            key={option.value}
            value={option.value}
            className={cn(
              POSTGRES_SEGMENTED_TOGGLE_ITEM_CLASS,
              variant === 'inline' && 'min-w-[5.5rem] px-3',
            )}
          >
            {option.label}
          </ToggleGroupItem>
        ))}
      </ToggleGroup>
    </div>
  )
}
