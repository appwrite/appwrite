import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'

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

  useKeyboardShortcut('meta+k', onOpenCommandCenter, { enabled: active })
  useKeyboardShortcut('control+k', onOpenCommandCenter, { enabled: active })

  useKeyboardShortcut(
    '/',
    (e) => {
      e.preventDefault()
      onOpenCommandCenter()
    },
    { enabled: active },
  )

  // ? is shift+/ on US layouts; register both forms.
  useKeyboardShortcut(
    'shift+?',
    (e) => {
      e.preventDefault()
      onOpenShortcutsHelp()
    },
    { enabled: active },
  )

  useKeyboardShortcut(
    'shift+/',
    (e) => {
      e.preventDefault()
      onOpenShortcutsHelp()
    },
    { enabled: active },
  )
}
