import * as React from 'react'

import { Input } from '@/components/ui/input'
import { parseNumericField, settleNumericField } from '@/lib/numeric-field'

export type NumberInputProps = Omit<
  React.ComponentProps<typeof Input>,
  'type' | 'value'
> & {
  /** The current numeric value. */
  value: number
  /** Called with the value the field holds, and again with the settled value on blur. */
  onValueChange: (value: number) => void
}

/**
 * Numeric input that stays editable while it is empty.
 *
 * A plain `<Input type="number">` bound to a number pushes a value back into the field the moment
 * it is cleared, so the last digit can never be deleted. This keeps the typed text while the field
 * is being edited and only settles on a number on blur: an empty or unreadable field goes back to
 * the value it had when editing started, and anything outside `min`/`max` is pulled into range.
 */
function NumberInput({
  value,
  onValueChange,
  min,
  max,
  onChange,
  onFocus,
  onBlur,
  ...props
}: NumberInputProps) {
  const [draft, setDraft] = React.useState<string | null>(null)
  // What a field cleared and then left empty goes back to: the value it held when editing
  // started, not the half-deleted number the last keystroke happened to leave behind.
  const valueOnFocus = React.useRef(value)

  return (
    <Input
      {...props}
      type="number"
      min={min}
      max={max}
      value={draft ?? String(value)}
      onChange={(event) => {
        setDraft(event.target.value)

        const parsed = parseNumericField(event.target.value)
        if (parsed !== undefined) onValueChange(parsed)

        onChange?.(event)
      }}
      onFocus={(event) => {
        valueOnFocus.current = value
        onFocus?.(event)
      }}
      onBlur={(event) => {
        setDraft(null)

        const settled = settleNumericField({
          field: event.target.value,
          previous: valueOnFocus.current,
          min,
          max,
        })
        if (settled !== value) onValueChange(settled)

        onBlur?.(event)
      }}
    />
  )
}

export { NumberInput }
