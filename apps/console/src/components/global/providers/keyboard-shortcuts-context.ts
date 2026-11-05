import { createContext, useContext } from 'react'
import type { CommandCenterPage } from '@/lib/command-center/opener-bridge'

export type { CommandCenterPage }

export type KeyboardShortcutsContextValue = {
  openCommandCenter: () => void
  /** Open Command Center directly on a named sub-page (protocol / agent). */
  openCommandCenterPage: (page: CommandCenterPage) => void
  openBlogSearch: () => void
  closeCommandCenter: () => void
  isCommandCenterOpen: boolean
}

export const KeyboardShortcutsContext =
  createContext<KeyboardShortcutsContextValue | null>(null)

/** Fallback when outside a provider (agent pane / pages without a CC host). */
const defaultContextValue: KeyboardShortcutsContextValue = {
  openCommandCenter: () => {},
  openCommandCenterPage: () => {},
  openBlogSearch: () => {},
  closeCommandCenter: () => {},
  isCommandCenterOpen: false,
}

export function useKeyboardShortcutsContext() {
  const context = useContext(KeyboardShortcutsContext)
  // Return default context if not within provider (e.g., agent pane / org overview)
  return context ?? defaultContextValue
}
