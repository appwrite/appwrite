import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
    <div className={cn('space-y-2.5', compact && 'space-y-2')}>
      {compact ? null : (
        <Label htmlFor={id} className="text-[12px] font-medium">
          {t('Default value')}
        </Label>
      )}
      <ToggleGroup
        type="single"
        variant="outline"
        size="sm"
        value={kind}
        onValueChange={(next) => {
          if (next === 'value' || next === 'expression') onKindChange(next)
        }}
        className="justify-start"
        aria-label={t('Default value')}
      >
        <ToggleGroupItem value="value" className="h-8 px-3.5 text-[12px]">
          {t('Value')}
        </ToggleGroupItem>
        <ToggleGroupItem value="expression" className="h-8 px-3.5 text-[12px]">
          {t('Expression')}
        </ToggleGroupItem>
      </ToggleGroup>
      <div className="relative">
        <Input
          id={id}
          value={isNull ? '' : value}
          onChange={(event) => {
            if (isNull) onNullChange(false)
            onValueChange(event.target.value)
          }}
          className={cn(
            'font-mono pr-[5.25rem]',
            compact && 'h-8 text-[12px]',
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
            'absolute inset-y-0 right-1.5 flex items-center gap-1.5 rounded-md px-1.5',
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
