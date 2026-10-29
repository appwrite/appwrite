function isMonacoEditorFocused(): boolean {
  if (typeof document === 'undefined') return false
  return document.querySelector('.monaco-editor:focus-within') !== null
}

/**
 * Whether global console shortcuts should be ignored - user is typing in a field
 * or in Monaco (focus may not be a plain textarea from document.activeElement’s perspective in some cases).
 */
export function shouldSuppressGlobalShortcuts(
  focusTarget: EventTarget | null | undefined,
): boolean {
  if (isMonacoEditorFocused()) {
    return true
  }

  if (focusTarget == null || !(focusTarget instanceof Element)) {
    return false
  }
  const el = focusTarget as HTMLElement
  if (el.closest?.('.monaco-editor, [data-postgres-sql-editor]')) {
    return true
  }
  const tag = el.tagName
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
    return true
  }
  if (el.isContentEditable) {
    return true
  }
  if (el.getAttribute('contenteditable') === 'true') {
    return true
  }
  return false
}
