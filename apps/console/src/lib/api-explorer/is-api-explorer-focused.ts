export function isApiExplorerFocused(): boolean {
  if (typeof document === 'undefined') return false

  const active = document.activeElement
  if (!(active instanceof Element)) return false

  return Boolean(active.closest('[data-api-explorer]'))
}
