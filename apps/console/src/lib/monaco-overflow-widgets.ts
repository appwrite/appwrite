const OVERFLOW_WIDGETS_ROOT_ID = 'monaco-overflow-widgets'

/**
 * Host for Monaco overflow widgets (suggest, hover, parameter hints).
 * Must live on `document.body` so `overflow-hidden` editor ancestors cannot clip them.
 */
export function getMonacoOverflowWidgetsRoot(): HTMLElement | undefined {
  if (typeof document === 'undefined') return undefined

  const existing = document.getElementById(OVERFLOW_WIDGETS_ROOT_ID)
  if (existing) return existing

  const root = document.createElement('div')
  root.id = OVERFLOW_WIDGETS_ROOT_ID
  root.className = 'monaco-overflow-widgets-root'
  document.body.appendChild(root)
  return root
}
