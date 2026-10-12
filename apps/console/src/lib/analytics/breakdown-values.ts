/**
 * Whether a breakdown value is a real, known value.
 *
 * The API returns an empty value when a dimension couldn't be derived for
 * those events (no referrer, unparseable user agent, …), the geo database
 * uses `--` for unknown locations, and some enrichers emit a literal
 * "Unknown". None of those are useful rows in a ranked card, so cards drop
 * them and compute shares among the known values only.
 */
export function isKnownBreakdownValue(value: string | null | undefined): boolean {
  const trimmed = value?.trim() ?? ''
  if (!trimmed || trimmed === '--') return false
  return trimmed.toLowerCase() !== 'unknown'
}
