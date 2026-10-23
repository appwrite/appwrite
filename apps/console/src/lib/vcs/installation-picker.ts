/**
 * Installation picker request
 *
 * A "Connect to GitHub" link asks to pick an installation before leaving for
 * the provider. The picker dialog is mounted once at the root, because many
 * of those links live in dropdowns that unmount as soon as they are clicked.
 */

import { useSyncExternalStore } from 'react'
import type { VcsProviderId } from '@/lib/vcs/providers'

export interface InstallationPickerRequest {
  projectId: string
  provider: VcsProviderId
  /** Provider authorize URL the link would have opened. */
  href: string
  target?: string
}

let request: InstallationPickerRequest | null = null
const listeners = new Set<() => void>()

function emit() {
  for (const listener of listeners) listener()
}

export function openInstallationPicker(next: InstallationPickerRequest) {
  request = next
  emit()
}

export function closeInstallationPicker() {
  request = null
  emit()
}

function subscribe(listener: () => void) {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

export function useInstallationPickerRequest() {
  return useSyncExternalStore(
    subscribe,
    () => request,
    () => null,
  )
}
