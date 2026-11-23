/**
 * Helpers to avoid Radix leaving `pointer-events: none` stuck on `<body>`.
 *
 * Modal DropdownMenu / ContextMenu / Popover lock pointer-events while open.
 * Two races leave that lock stuck (page unscrollable, buttons dead):
 *
 * 1. Opening a Dialog/Drawer in the same tick the menu is still closing.
 * 2. Keeping a Dialog open while a mutation refetches and unmounts the row
 *    menu that owned the dialog.
 *
 * @see DeleteRule.tsx (firewall) and CreateDeploymentDropdown.tsx
 */

import { flushSync } from 'react-dom'

/**
 * Open a Dialog/Drawer after a Radix modal overlay has finished cleaning up.
 * Call from DropdownMenuItem / ContextMenuItem `onSelect` (or equivalent).
 */
export function openDialogAfterOverlayCloses(open: () => void): void {
  if (
    typeof document !== 'undefined' &&
    document.activeElement instanceof HTMLElement
  ) {
    document.activeElement.blur()
  }
  window.setTimeout(open, 0)
}

/**
 * Close a Dialog and commit the update before a mutation that may refetch and
 * unmount the menu that owned the dialog. Call this before mutate/refetch.
 */
export function closeDialogBeforeOverlayUnmount(close: () => void): void {
  flushSync(close)
}
