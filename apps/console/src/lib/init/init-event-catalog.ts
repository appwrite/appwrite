/** Metadata for Init events shown in browse UI and debug menus. */
export const INIT_EVENT_CATALOG: Record<
  string,
  { edition: number; editionLabel: string }
> = {
  'init-july-2026': { edition: 4, editionLabel: 'Init 4' },
  'init-nov-2026': { edition: 5, editionLabel: 'Init 5' },
}

/** Debug-only events without full page content. */
const INIT_DEBUG_ONLY_EVENT_IDS = new Set(['init-sep-2026'])

export function isKnownLaunchEventId(id: string): boolean {
  return id in INIT_EVENT_CATALOG || INIT_DEBUG_ONLY_EVENT_IDS.has(id)
}

export function formatInitEventDebugLabel(
  eventId: string,
  dateRangeLabel?: string,
): string {
  const catalog = INIT_EVENT_CATALOG[eventId]
  if (!catalog) return eventId
  return dateRangeLabel
    ? `${catalog.editionLabel} · ${dateRangeLabel}`
    : catalog.editionLabel
}
