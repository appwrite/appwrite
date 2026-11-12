/**
 * Number inputs bound straight to a number in state used `Number(event.target.value) || fallback`
 * in the change handler, which puts a value back into the field the moment it is emptied: the last
 * digit can never be deleted, so a rate limit of `1` could only grow to `12`, never become `2`.
 * Editing has to tolerate an empty field, so the text is only turned back into a number once
 * editing ends.
 */

/** A number input's `min` / `max`, which React types as either a number or the raw attribute. */
type NumericBound = string | number | undefined

function boundToNumber(bound: NumericBound): number | undefined {
  if (typeof bound === 'number') {
    return Number.isFinite(bound) ? bound : undefined
  }

  if (typeof bound === 'string' && bound.trim() !== '') {
    const parsed = Number(bound)
    return Number.isFinite(parsed) ? parsed : undefined
  }

  return undefined
}

/**
 * Reads the text held by a numeric field. Returns `undefined` only while the field is empty or
 * holds something that is not a number -- both of which are states the user passes through on the
 * way to a new value.
 */
export function parseNumericField(field: string): number | undefined {
  const trimmed = field.trim()

  if (trimmed === '') {
    return undefined
  }

  const parsed = Number(trimmed)

  return Number.isFinite(parsed) ? parsed : undefined
}

/**
 * The value a numeric field settles on when editing ends: what the user typed, held within
 * `min`/`max`, falling back to the previous value when the field was left empty or unreadable.
 */
export function settleNumericField({
  field,
  previous,
  min,
  max,
}: {
  field: string
  previous: number
  min?: NumericBound
  max?: NumericBound
}): number {
  const settled = parseNumericField(field) ?? previous
  const lower = boundToNumber(min)
  const upper = boundToNumber(max)

  if (lower !== undefined && settled < lower) return lower
  if (upper !== undefined && settled > upper) return upper

  return settled
}
