/**
 * EventEditorModal – modal wrapper for the event builder.
 * Use in webhooks and functions settings.
 */
import { EventEditor } from './EventEditor'
import type { EventEditorModalProps } from './types'

export function EventEditorModal(props: EventEditorModalProps) {
  return <EventEditor {...props} />
}
