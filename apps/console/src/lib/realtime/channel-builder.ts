import {
  buildEventString,
  parseEventString,
  type EventBuilderSelection,
} from '@/lib/events-editor/events-model'

export const REALTIME_CHANNELS_DOCS_LINK =
  '/docs/apis/realtime/subscribe'

/**
 * Build a Realtime channel string from event builder selection.
 * Omits action and attribute segments (e.g. databases.*.tables.*.rows.*).
 */
export function buildChannelString(sel: EventBuilderSelection): string {
  return buildEventString({ ...sel, action: null, attribute: null })
}

export function isValidChannelString(str: string): boolean {
  const trimmed = str.trim()
  if (!trimmed) return false
  if (parseEventString(trimmed) !== null) return true
  return /^[\w.*-]+(\.[\w*-]+)*$/.test(trimmed)
}
