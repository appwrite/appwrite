export const CLI_SHELL_ROOT_SELECTOR = '[data-cli-shell]'

/** True when keyboard focus is inside the project CLI shell panel. */
export function isFocusWithinCliShell(
  focusTarget: EventTarget | null = document.activeElement,
): boolean {
  if (focusTarget == null || !(focusTarget instanceof Element)) {
    return false
  }
  return focusTarget.closest(CLI_SHELL_ROOT_SELECTOR) !== null
}
