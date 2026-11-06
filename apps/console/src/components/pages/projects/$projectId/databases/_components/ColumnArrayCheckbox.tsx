import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { useT } from '@/lib/i18n/translate'

type ColumnArrayCheckboxProps = {
  id: string
  checked: boolean
  onCheckedChange: (checked: boolean) => void
  disabled?: boolean
  /** TablesDB-style label only, or Postgres-style with description */
  variant?: 'compact' | 'detailed'
}

export function ColumnArrayCheckbox({
  id,
  checked,
  onCheckedChange,
  disabled = false,
  variant = 'compact',
}: ColumnArrayCheckboxProps) {
  const t = useT()

  if (variant === 'detailed') {
    return (
      <div className="flex items-start gap-2">
        <Checkbox
          id={id}
          checked={checked}
          onCheckedChange={(value) => onCheckedChange(value === true)}
          disabled={disabled}
          className="mt-0.5"
        />
        <div className="space-y-1">
          <Label htmlFor={id} className="text-[12px] font-medium leading-none">
            {t('Define as array')}
          </Label>
          <p className="text-[11px] text-muted-foreground">
            {t('Store multiple values of this type in a single column.')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex items-center space-x-2">
      <Checkbox
        id={id}
        checked={checked}
        onCheckedChange={(value) => onCheckedChange(value === true)}
        disabled={disabled}
      />
      <Label
        htmlFor={id}
        className="text-[12px] font-normal cursor-pointer"
      >
        {t('Array')}
      </Label>
    </div>
  )
}
