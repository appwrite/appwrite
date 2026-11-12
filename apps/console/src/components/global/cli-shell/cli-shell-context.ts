import { createContext, useContext } from 'react'
import type { CliTerminalSearchResults } from '@/lib/cli-shell/cli-terminal-search-label'
import type { CliShellStatus } from '@/lib/cli-shell/types'
import type { CliTerminalApi } from '@/lib/cli-shell/cli-terminal-api'
import type { CliShellSession } from '@/lib/cli-shell/cli-shell-sessions'

export type { CliTerminalSearchResults }

export type CliShellContextValue = {
  open: boolean
  setOpen: (open: boolean) => void
  /** True once the panel has been opened; keeps terminal instances mounted when collapsed. */
  panelEverOpened: boolean
  toggle: () => void
  status: CliShellStatus
  sessions: CliShellSession[]
  activeSessionId: string
  setActiveSessionId: (sessionId: string) => void
  selectSession: (sessionId: string) => void
  focusedSessionId: string
  focusSessionPane: (sessionId: string) => void
  splitPaneSessionIds: string[]
  visiblePaneSessionIds: string[]
  splitSession: (sessionId: string) => void
  createSession: () => void
  removeSession: (sessionId: string) => void
  renameSession: (sessionId: string, name: string) => void
  reorderRootSessions: (fromIndex: number, toIndex: number) => void
  registerTerminal: (sessionId: string, api: CliTerminalApi) => void
  unregisterTerminal: (sessionId: string) => void
  writeSessionWelcome: (sessionId: string) => Promise<boolean>
  runCommand: (command: string, sessionId?: string) => Promise<void>
  clearOutput: (sessionId?: string) => void
  completeTab: (
    input: string,
    cursor: number,
    listOnly?: boolean,
    sessionId?: string,
  ) => { input: string; cursor: number } | null
  cancelRunning: (sessionId?: string) => void
  isRunning: boolean
  runningSessionId: string | null
  isSessionRunning: (sessionId: string) => boolean
  height: number
  setHeight: (height: number) => void
  fullscreen: boolean
  toggleFullscreen: () => void
  exitFullscreen: () => void
  retryBootstrap: () => void
  bootstrapError: string | null
  getTerminalPrompt: () => string
  getCommandHistory: () => string[]
  persistCommandHistory: (history: string[]) => void
  getSuggestionCommands: () => readonly string[]
  terminalSearchOpen: boolean
  terminalSearchQuery: string
  searchSessionIds: string[]
  searchCaseSensitive: boolean
  setTerminalSearchOpen: (open: boolean) => void
  terminalSearchResults: CliTerminalSearchResults | null
  reportSearchResults: (
    sessionId: string,
    results: CliTerminalSearchResults,
  ) => void
  toggleTerminalSearch: () => void
  searchTerminalOutput: (
    query: string,
    options: { caseSensitive: boolean },
    sessionId?: string,
  ) => void
  findNextTerminalMatch: () => void
  findPreviousTerminalMatch: () => void
  copyTerminalSelection: () => void
  copyLastCommand: () => void
  copyTerminalOutput: () => void
  exportTerminalOutput: () => void
}

export const CliShellContext = createContext<CliShellContextValue | null>(null)

export function useCliShell() {
  const ctx = useContext(CliShellContext)
  if (!ctx) {
    throw new Error('useCliShell must be used within CliShellProvider')
  }
  return ctx
}

/** Safe hook for components that may render outside the provider. */
export function useCliShellOptional() {
  return useContext(CliShellContext)
}
