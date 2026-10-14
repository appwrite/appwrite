/**
 * Description Field Component
 *
 * Optional `description` input shared by the settings cards and create forms of
 * every resource that carries one (functions, sites, buckets, topics,
 * databases). Clamps to the API's 1024 char limit and shows the character
 * count the same way the support and sales wizards do.
 */

import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { cn } from '@/lib/utils'
import { DESCRIPTION_MAX_LENGTH } from '@/lib/appwrite/resource-descriptions'
import { useT } from '@/lib/i18n/translate'

export interface DescriptionFieldProps {
  /** Unique id so the label targets this field. */
  id: string
  value: string
  onChange: (value: string) => void
  /** Field label. Defaults to "Description (optional)". */
  label?: string
  placeholder?: string
  /** Helper copy under the field, in place of the character count. */
  hint?: string
  disabled?: boolean
  className?: string
}

export function DescriptionField({
  id,
  value,
  onChange,
  label,
  placeholder,
  hint,
  disabled = false,
  className,
}: DescriptionFieldProps) {
  const t = useT()

  return (
    <div className={cn(className)}>
      <Label htmlFor={id} className="text-[13px]">
        {label ?? t('Description (optional)')}
      </Label>
      <Textarea
        id={id}
        value={value}
        onChange={(e) =>
          onChange(e.target.value.slice(0, DESCRIPTION_MAX_LENGTH))
        }
        placeholder={placeholder ?? t('What is this for?')}
        maxLength={DESCRIPTION_MAX_LENGTH}
        disabled={disabled}
        className="mt-2 min-h-[72px] resize-y text-[13px]"
      />
      <p className="mt-1 text-[12px] text-muted-foreground">
        {hint ?? `${value.length}/${DESCRIPTION_MAX_LENGTH} ${t('characters')}`}
      </p>
    </div>
  )
}
