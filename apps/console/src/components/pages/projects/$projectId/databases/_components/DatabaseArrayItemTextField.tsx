import { Checkbox } from '@/components/ui/checkbox'
import { Textarea } from '@/components/ui/textarea'
import { isSpreadsheetRtlText } from '@/lib/spreadsheet-cell-formatting'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

/** Full-width array item textarea; overrides ui/Textarea field-sizing-content shrink. */
export const DATABASE_ARRAY_ITEM_TEXTAREA_CLASS =
  'field-sizing-fixed w-full min-h-[36px] max-h-[600px] resize-none rounded-none border-0 bg-transparent ps-3 pe-3 py-2 text-[13px] text-start shadow-none focus-visible:ring-0 focus-visible:ring-offset-0'

/** Row-edit scalar textarea; same field-sizing fix as array items. */
export const DATABASE_ROW_TEXTAREA_CLASS =
  'field-sizing-fixed w-full min-h-[36px] max-h-[600px] resize-none text-[13px] text-start'

/** Reserve space for the null/counter overlay on the inline-end side (follows field dir). */
export const DATABASE_ROW_FIELD_COUNTER_PADDING = 'pb-8 pe-28'

/** Inline (single-line) fields: horizontal reserve only. */
export const DATABASE_ROW_FIELD_INLINE_COUNTER_PADDING = 'pe-28'

type DatabaseArrayItemTextFieldProps = {
  id?: string
  value: string
  onChange: (value: string) => void
  onFocus?: () => void
  inputRef?: (el: HTMLTextAreaElement | null) => void
  disabled?: boolean
  autoFocus?: boolean
  maxLength?: number
  placeholder?: string
  isNull?: boolean
  showNullCheckbox?: boolean
  nullCheckboxId: string
  onNullChange?: (isNull: boolean) => void
}

export function DatabaseArrayItemTextField({
  id,
  value,
  onChange,
  onFocus,
  inputRef,
  disabled = false,
  autoFocus = false,
  maxLength,
  placeholder,
  isNull = false,
  showNullCheckbox = false,
  nullCheckboxId,
  onNullChange,
}: DatabaseArrayItemTextFieldProps) {
  const t = useT()
  const stringValue = isNull ? '' : value
  const charCount = stringValue.length
  const isRTL = isSpreadsheetRtlText(stringValue)
  const hasLimit = maxLength != null && maxLength > 0
  const showFooter = hasLimit || showNullCheckbox

  return (
    <div
      className="flex min-w-0 w-full flex-1 flex-col"
      dir={isRTL ? 'rtl' : 'ltr'}
    >
      <Textarea
        id={id}
        value={stringValue}
        ref={inputRef}
        autoFocus={autoFocus}
        disabled={disabled || isNull}
        dir={isRTL ? 'rtl' : 'ltr'}
        maxLength={hasLimit ? maxLength : undefined}
        placeholder={placeholder}
        rows={1}
        onFocus={onFocus}
        onChange={(event) => {
          event.stopPropagation()
          onChange(event.target.value)
        }}
        className={cn(
          DATABASE_ARRAY_ITEM_TEXTAREA_CLASS,
          isNull && 'cursor-not-allowed opacity-50',
        )}
      />
      {showFooter ? (
        <div className="flex items-center justify-end gap-3 border-t border-foreground/10 bg-muted/30 px-3 py-1">
          {hasLimit ? (
            <span
              className={cn(
                'text-[10px] tabular-nums whitespace-nowrap',
                charCount > (maxLength ?? 0)
                  ? 'font-medium text-destructive'
                  : 'text-muted-foreground',
              )}
            >
              {charCount}/{maxLength}
            </span>
          ) : null}
          {showNullCheckbox ? (
            <label
              htmlFor={nullCheckboxId}
              className="flex cursor-pointer select-none items-center gap-1.5 text-[10px] text-muted-foreground"
            >
              <Checkbox
                id={nullCheckboxId}
                checked={isNull}
                disabled={disabled}
                onCheckedChange={(checked) => onNullChange?.(checked === true)}
                onClick={(event) => event.stopPropagation()}
                className="h-3 w-3 cursor-pointer"
              />
              {t('Null')}
            </label>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}

export function databaseRowTextareaPadding(options: {
  hasLimit?: boolean
  showNullCheckbox?: boolean
}): string {
  const needsCounterSpace = options.hasLimit || options.showNullCheckbox
  if (!needsCounterSpace) return 'pb-2'
  return DATABASE_ROW_FIELD_COUNTER_PADDING
}

export function databaseRowFieldOverlayClass(
  variant: 'textarea' | 'input',
): string {
  return cn(
    'pointer-events-none absolute flex items-center gap-2 end-2',
    variant === 'textarea'
      ? 'bottom-2'
      : 'top-1/2 -translate-y-1/2',
  )
}
