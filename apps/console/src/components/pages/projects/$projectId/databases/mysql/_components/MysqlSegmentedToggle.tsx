import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { MYSQL_SEGMENTED_TOGGLE_ITEM_CLASS } from './mysql-chrome'

type MysqlSegmentedToggleOption<T extends string> = {
  value: T
  label: string
}

type MysqlSegmentedToggleProps<T extends string> = {
  value: T
  onValueChange: (value: T) => void
  options: MysqlSegmentedToggleOption<T>[]
  ariaLabel: string
  /** Full-width header bar (sidebar Data / Queries / History). */
  variant?: 'bar' | 'inline'
  className?: string
}

export function MysqlSegmentedToggle<T extends string>({
  value,
  onValueChange,
  options,
  ariaLabel,
  variant = 'inline',
  className,
}: MysqlSegmentedToggleProps<T>) {
  const t = useT()
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
        aria-label={t(ariaLabel)}
      >
        {options.map((option) => (
          <ToggleGroupItem
            key={option.value}
            value={option.value}
            className={cn(
              MYSQL_SEGMENTED_TOGGLE_ITEM_CLASS,
              variant === 'inline' && 'min-w-[5.5rem] px-3',
            )}
          >
            {t(option.label)}
          </ToggleGroupItem>
        ))}
      </ToggleGroup>
    </div>
  )
}
