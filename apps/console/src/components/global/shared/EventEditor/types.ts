export interface EventEditorModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  initialValue?: string
  onCreated: (eventString: string) => void
  description?: string
  /** Required for database/table/bucket selectors; omit to show only * */
  projectId?: string | null
  /** When true, builds Realtime channel strings (no action/attribute segment). */
  channelMode?: boolean
  docsLink?: string
  confirmLabel?: string
  title?: string
}
