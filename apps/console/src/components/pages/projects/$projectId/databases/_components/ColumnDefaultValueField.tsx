import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useT } from '@/lib/i18n/translate'
import type { SqlColumnDefaultKind } from '@/lib/sql-column-default'
import { cn } from '@/lib/utils'

type ColumnDefaultValueFieldProps = {
  id: string
  kind: SqlColumnDefaultKind
  value: string
  isNull: boolean
  onKindChange: (kind: SqlColumnDefaultKind) => void
  onValueChange: (value: string) => void
  onNullChange: (isNull: boolean) => void
  expressionPlaceholder: string
  expressionHint: string
  compact?: boolean
  inputClassName?: string
  nullDisabled?: boolean
}

export function ColumnDefaultValueField({
  id,
  kind,
  value,
  isNull,
  onKindChange,
  onValueChange,
  onNullChange,
  expressionPlaceholder,
  expressionHint,
  compact = false,
  inputClassName,
  nullDisabled = false,
}: ColumnDefaultValueFieldProps) {
  const t = useT()

  return (
    <div className={cn('space-y-2.5', compact && 'space-y-0')}>
      {compact ? null : (
        <Label htmlFor={id} className="text-[12px] font-medium">
          {t('Default value')}
        </Label>
      )}
      <div
        className={cn(
          'flex min-w-0 overflow-hidden rounded-md border border-input bg-transparent transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30',
          compact ? 'h-8' : 'h-9',
        )}
      >
        <Select
          value={kind}
          onValueChange={(next) => {
            if (next === 'value' || next === 'expression') onKindChange(next)
          }}
        >
          <SelectTrigger
            size={compact ? 'sm' : 'default'}
            aria-label={t('Default value')}
            className={cn(
              'h-full w-[7.25rem] shrink-0 rounded-none border-0 bg-transparent px-2.5 text-[12px] shadow-none focus-visible:border-0 focus-visible:ring-0 dark:bg-transparent dark:hover:bg-transparent',
              compact && 'h-8',
            )}
          >
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="value" className="text-[12px]">
              {t('Value')}
            </SelectItem>
            <SelectItem value="expression" className="text-[12px]">
              {t('Expression')}
            </SelectItem>
          </SelectContent>
        </Select>
        <div className="w-px shrink-0 bg-border" aria-hidden="true" />
        <div className="relative min-w-0 flex-1">
          <Input
            id={id}
            value={isNull ? '' : value}
            onChange={(event) => {
              if (isNull) onNullChange(false)
              onValueChange(event.target.value)
            }}
            className={cn(
              'h-full rounded-none border-0 bg-transparent font-mono shadow-none focus-visible:border-0 focus-visible:ring-0 dark:bg-transparent',
              compact ? 'h-8 px-2 text-[12px]' : 'h-9',
              'pe-[5.25rem]',
              isNull && 'text-muted-foreground',
              inputClassName,
            )}
            placeholder={
              isNull
                ? undefined
                : kind === 'expression'
                  ? t(expressionPlaceholder)
                  : undefined
            }
          />
          <div
            className={cn(
              'absolute inset-y-0 end-1.5 flex items-center gap-1.5 rounded-md px-1.5',
              nullDisabled
                ? 'cursor-not-allowed opacity-50'
                : 'cursor-pointer text-muted-foreground hover:text-foreground',
              isNull && !nullDisabled && 'text-foreground',
            )}
          >
            <Checkbox
              checked={isNull}
              disabled={nullDisabled}
              onCheckedChange={(checked) => {
                onNullChange(checked === true)
              }}
              className={cn('size-3.5', compact && 'size-3')}
              aria-label="NULL"
            />
            <button
              type="button"
              disabled={nullDisabled}
              onClick={() => onNullChange(!isNull)}
              className="text-[11px] font-mono leading-none disabled:cursor-not-allowed"
            >
              NULL
            </button>
          </div>
        </div>
      </div>
      {compact ? null : (
        <p className="text-[11px] text-muted-foreground">
          {kind === 'expression'
            ? t(expressionHint)
            : t('Stored as data. Quotes and escaping are applied for you.')}
        </p>
      )}
    </div>
  )
}
