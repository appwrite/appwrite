import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  OVERVIEW_STORAGE_BREAKDOWN_OPTIONS,
  type OverviewStorageBreakdownType,
} from '@/lib/usage/storage-usage'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const TOGGLE_ITEM_CLASS =
  'h-8 w-full min-w-0 truncate px-1 text-[11px] font-medium text-muted-foreground hover:text-foreground data-[state=on]:bg-secondary data-[state=on]:text-secondary-foreground data-[state=on]:hover:bg-secondary data-[state=on]:hover:text-secondary-foreground'

type OverviewStorageBreakdownToggleProps = {
  value: OverviewStorageBreakdownType
  onValueChange: (value: OverviewStorageBreakdownType) => void
  className?: string
}

export function OverviewStorageBreakdownToggle({
  value,
  onValueChange,
  className,
}: OverviewStorageBreakdownToggleProps) {
  const t = useT()
  const optionCount = OVERVIEW_STORAGE_BREAKDOWN_OPTIONS.length

  return (
    <ToggleGroup
      type="single"
      variant="outline"
      size="sm"
      value={value}
      onValueChange={(next) => {
        if (!next) return
        if (
          OVERVIEW_STORAGE_BREAKDOWN_OPTIONS.some(
            (option) => option.value === next,
          )
        ) {
          onValueChange(next as OverviewStorageBreakdownType)
        }
      }}
      className={cn('grid h-8 w-full min-w-0 gap-0 rounded-md', className)}
      style={{
        gridTemplateColumns: `repeat(${optionCount}, minmax(0, 1fr))`,
      }}
      aria-label={t('Storage breakdown type')}
    >
      {OVERVIEW_STORAGE_BREAKDOWN_OPTIONS.map((option, index) => {
        const isFirst = index === 0
        const isLast = index === optionCount - 1

        return (
          <ToggleGroupItem
            key={option.value}
            value={option.value}
            className={cn(
              TOGGLE_ITEM_CLASS,
              '!rounded-none shadow-none',
              isFirst && '!rounded-s-md !border-s',
              isLast && '!rounded-e-md',
              !isFirst && '!border-s-0',
            )}
          >
            {t(option.label)}
          </ToggleGroupItem>
        )
      })}
    </ToggleGroup>
  )
}
