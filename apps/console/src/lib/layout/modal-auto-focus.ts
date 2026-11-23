const MODAL_TEXT_ENTRY_SELECTOR = [
  'input:not([type="hidden"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="checkbox"]):not([type="radio"]):not([type="file"]):not([disabled]):not([readonly]):not([tabindex="-1"])',
  'textarea:not([disabled]):not([readonly]):not([tabindex="-1"])',
  'select:not([disabled]):not([tabindex="-1"])',
  '[contenteditable="true"]:not([tabindex="-1"])',
].join(', ')

const MODAL_AUTO_FOCUS_SKIP_SELECTOR = [
  '[data-slot="dialog-close"]',
  '[role="tab"]',
  '[data-slot="tabs-trigger"]',
].join(', ')

function shouldSkipModalAutoFocusTarget(element: Element): boolean {
  return (
    element.matches('[role="tab"], [data-slot="tabs-trigger"]') ||
    !!element.closest(MODAL_AUTO_FOCUS_SKIP_SELECTOR)
  )
}

export function findFirstModalTextEntry(
  root: ParentNode,
): HTMLElement | null {
  const candidates = root.querySelectorAll<HTMLElement>(
    MODAL_TEXT_ENTRY_SELECTOR,
  )

  for (const candidate of candidates) {
    if (shouldSkipModalAutoFocusTarget(candidate)) continue
    return candidate
  }

  return null
}

function placeModalCaretAtEnd(element: HTMLElement): void {
  if (
    element instanceof HTMLInputElement ||
    element instanceof HTMLTextAreaElement
  ) {
    const len = element.value.length
    if (len > 0) {
      try {
        element.setSelectionRange(len, len)
      } catch {
        // Some input types (e.g. number, email) don't support selection APIs.
      }
    }
    return
  }

  if (element.isContentEditable) {
    const selection = window.getSelection()
    if (!selection) return

    const range = document.createRange()
    range.selectNodeContents(element)
    range.collapse(false)
    selection.removeAllRanges()
    selection.addRange(range)
  }
}

export function focusModalTextEntry(element: HTMLElement): void {
  // Run after Radix's focus trap settles, then again on the next frame to
  // override any onFocus handlers that select-all.
  setTimeout(() => {
    element.focus({ preventScroll: false })
    placeModalCaretAtEnd(element)
    requestAnimationFrame(() => {
      placeModalCaretAtEnd(element)
    })
  }, 0)
}

export function handleModalOpenAutoFocus(
  event: Event,
  root?: ParentNode | null,
): void {
  event.preventDefault()

  const focusRoot = root ?? (event.currentTarget as ParentNode | null)
  if (!focusRoot) return

  const firstInput = findFirstModalTextEntry(focusRoot)
  if (!firstInput) return

  focusModalTextEntry(firstInput)
}
