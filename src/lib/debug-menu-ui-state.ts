const DEBUG_MENU_UI_STATE_KEY = 'debug:menuUiState'

export type DebugMenuUiState = {
  popoverOpen: boolean
  activeSubmenu: string | null
}

const DEFAULT_STATE: DebugMenuUiState = {
  popoverOpen: false,
  activeSubmenu: null,
}

export function readDebugMenuUiState(): DebugMenuUiState {
  if (typeof window === 'undefined') return DEFAULT_STATE
  try {
    const raw = window.localStorage.getItem(DEBUG_MENU_UI_STATE_KEY)
    if (!raw) return DEFAULT_STATE
    const parsed = JSON.parse(raw) as DebugMenuUiState
    return {
      popoverOpen: Boolean(parsed.popoverOpen),
      activeSubmenu:
        typeof parsed.activeSubmenu === 'string'
          ? parsed.activeSubmenu
          : parsed.activeSubmenu === null
            ? null
            : null,
    }
  } catch {
    return DEFAULT_STATE
  }
}

export function writeDebugMenuUiState(state: DebugMenuUiState) {
  if (typeof window === 'undefined') return
  window.localStorage.setItem(DEBUG_MENU_UI_STATE_KEY, JSON.stringify(state))
}
