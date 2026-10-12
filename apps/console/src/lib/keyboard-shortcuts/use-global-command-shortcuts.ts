import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { useThemeShortcuts } from '@/lib/keyboard-shortcuts/theme-shortcuts'

/** Cmd/Ctrl+K must work inside Monaco and other text fields. */
export const OPEN_COMMAND_CENTER_SHORTCUT_OPTIONS = {
  capture: true,
  ignoreInputs: false,
  stopPropagation: true,
} as const

interface UseGlobalCommandShortcutsOptions {
  commandCenterOpen: boolean
  onOpenCommandCenter: () => void
  onOpenShortcutsHelp: () => void
  enabled?: boolean
}

/**
 * Shared global shortcuts for opening the command center and shortcuts help.
 */
export function useGlobalCommandShortcuts({
  commandCenterOpen,
  onOpenCommandCenter,
  onOpenShortcutsHelp,
  enabled = true,
}: UseGlobalCommandShortcutsOptions) {
  const active = enabled && !commandCenterOpen

  const openCommandCenterShortcut = {
    ...OPEN_COMMAND_CENTER_SHORTCUT_OPTIONS,
    enabled: active,
  }

  useKeyboardShortcut('meta+k', onOpenCommandCenter, openCommandCenterShortcut)
  useKeyboardShortcut('control+k', onOpenCommandCenter, openCommandCenterShortcut)

  useKeyboardShortcut(
    '/',
    (e) => {
      e.preventDefault()
      onOpenCommandCenter()
    },
    { enabled: active, capture: true },
  )

  // ? is shift+/ on US layouts; register both forms.
  useKeyboardShortcut(
    'shift+?',
    (e) => {
      e.preventDefault()
      onOpenShortcutsHelp()
    },
    { enabled: active, capture: true },
  )

  useKeyboardShortcut(
    'shift+/',
    (e) => {
      e.preventDefault()
      onOpenShortcutsHelp()
    },
    { enabled: active, capture: true },
  )

  useThemeShortcuts({ enabled: active })
}
