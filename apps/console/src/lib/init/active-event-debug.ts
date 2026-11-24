import { LAUNCH_EVENTS } from './events'
import {
  formatInitEventDebugLabel,
  isKnownLaunchEventId,
} from './init-event-catalog'

/** Production default featured Init event (Init 4). */
export const DEFAULT_FEATURED_INIT_EVENT_ID = 'init-july-2026'

export { isKnownLaunchEventId }

export function formatMockInitActiveEventLabel(id: string | null): string {
  if (!id) return 'Production default (Init 4 featured)'
  const event = LAUNCH_EVENTS.find((entry) => entry.id === id)
  return formatInitEventDebugLabel(id, event?.dateRangeLabel)
}

export function listInitActiveEventDebugOptions(): Array<{
  id: string | null
  label: string
}> {
  return [
    { id: null, label: 'Production default (Init 4 featured)' },
    ...LAUNCH_EVENTS.map((event) => ({
      id: event.id,
      label: formatInitEventDebugLabel(event.id, event.dateRangeLabel),
    })),
  ]
}
