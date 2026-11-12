import { useCallback } from 'react'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { isApiExplorerFocused } from './is-api-explorer-focused'
import { API_EXPLORER_SEND_REQUEST_SHORTCUT_COMBOS } from './shortcuts'

const EXPLORER_SHORTCUT_OPTIONS = {
  ignoreInputs: false,
  capture: true,
} as const

type UseApiExplorerShortcutsOptions = {
  onSendRequest: () => void
  enabled: boolean
}

export function useApiExplorerShortcuts({
  onSendRequest,
  enabled,
}: UseApiExplorerShortcutsOptions) {
  const handleSendRequest = useCallback(
    (event: KeyboardEvent) => {
      if (!isApiExplorerFocused()) return
      event.preventDefault()
      onSendRequest()
    },
    [onSendRequest],
  )

  useKeyboardShortcut(
    API_EXPLORER_SEND_REQUEST_SHORTCUT_COMBOS[0],
    handleSendRequest,
    {
      ...EXPLORER_SHORTCUT_OPTIONS,
      enabled,
    },
  )
  useKeyboardShortcut(
    API_EXPLORER_SEND_REQUEST_SHORTCUT_COMBOS[1],
    handleSendRequest,
    {
      ...EXPLORER_SHORTCUT_OPTIONS,
      enabled,
    },
  )
}
