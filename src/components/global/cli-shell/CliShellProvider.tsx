import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  getBaseEndpoint,
  getProjectApiEndpoint,
} from '@/lib/appwrite/sdk'
import {
  bootstrapCliRuntime,
  syncCliAuthFiles,
  syncCliProjectConfig,
} from '@/lib/cli-shell/bootstrap-cli-container'
import {
  CLI_BOOTSTRAP_READY_MESSAGE,
  CLI_PROJECT_CWD,
  CLI_SHELL_COLLAPSE_MS,
  CLI_SHELL_TRY_COMMANDS,
  createCliShellWelcomeLines,
} from '@/lib/cli-shell/constants'
import { resolveConsoleCliAuth } from '@/lib/cli-shell/console-session'
import {
  installCliFetchBridge,
  removeCliFetchBridge,
} from '@/lib/cli-shell/fetch-bridge'
import { getBlockedCliCommandMessage } from '@/lib/cli-shell/blocked-cli-commands'
import { isAppwriteCliCommand } from '@/lib/cli-shell/is-appwrite-command'
import { prepareCliCommand } from '@/lib/cli-shell/prepare-command'
import { shouldWriteCliStderr } from '@/lib/cli-shell/cli-output'
import {
  CLI_TERMINAL_MUTED,
  CLI_TERMINAL_RESET,
  createTerminalOutputLinkifier,
  formatCliTerminalPrompt,
  resolveCliTerminalProjectLabel,
  resolveCliTerminalUsername,
  type CliTerminalApi,
  writeCliShellLine,
  writeCliShellSuggestions,
  writeCliTerminalRaw,
} from '@/lib/cli-shell/cli-terminal-api'
import type { CliShellRunOptions } from '@/lib/cli-shell/types'
import {
  deleteProjectBootstrapState,
  getProjectBootstrapState,
} from '@/lib/cli-shell/bootstrap-state'
import { CLI_TERMINAL_CACHE_CLEARED } from '@/lib/cli-shell/clear-terminal-cache'
import {
  applyTabCompletion,
  tabComplete,
} from '@/lib/cli-shell/tab-completion'
import type {
  CliShellContainer,
  CliShellStatus,
} from '@/lib/cli-shell/types'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { CLI_SHELL_TOGGLE_SHORTCUT_COMBOS } from '@/lib/cli-shell/cli-terminal-shortcuts'
import {
  useCliShellHeight,
  useCliShellHistory,
  useCliShellOpen,
  useCliShellSessionsPrefs,
  type ConsoleAccountCache,
} from '@/lib/react-query/hooks/auth'
import { useProject } from '@/lib/react-query/hooks'
import { downloadTextFile } from '@/lib/cli-shell/cli-terminal-buffer'
import { isFocusWithinCliShell } from '@/lib/cli-shell/cli-shell-focus'
import {
  CLI_SHELL_FULLSCREEN_SHORTCUT_COMBOS,
  CLI_SHELL_NEW_TERMINAL_SHORTCUT_COMBOS,
  CLI_SHELL_SEARCH_SHORTCUT_COMBOS,
} from '@/lib/cli-shell/cli-terminal-shortcuts'
import {
  createCliShellSessionId,
  createInitialCliShellSessionState,
  nextCliShellSessionName,
  reorderCliShellRootSessions,
  type CliShellSession,
} from '@/lib/cli-shell/cli-shell-sessions'
import {
  collectCliShellSessionsToRemove,
  getCliShellSessionRootId,
  getCliShellVisiblePaneSessionIds,
  normalizeCliShellSplitPaneIds,
} from '@/lib/cli-shell/cli-shell-split'
import {
  clampCliShellHeightPx,
  MAX_CLI_SHELL_SESSIONS,
  MAX_CLI_SHELL_SESSION_NAME_LENGTH,
  parseCliShellSessions,
  serializeCliShellSessionsState,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import {
  aggregateCliTerminalSearchResults,
  resolveGlobalMatchPosition,
  type CliTerminalSearchResults,
} from '@/lib/cli-shell/cli-terminal-search-label'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

export type { CliTerminalSearchResults }

type CliShellContextValue = {
  open: boolean
  setOpen: (open: boolean) => void
  /** True once the panel has been opened; keeps terminal instances mounted when minimized. */
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

const CliShellContext = createContext<CliShellContextValue | null>(null)

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

function isEditablePageFocusTarget(element: Element | null): boolean {
  if (!element || element === document.body) return false
  if (
    element.closest(
      '.monaco-editor, [data-postgres-sql-editor], [contenteditable="true"]',
    )
  ) {
    return true
  }
  return (
    element instanceof HTMLInputElement ||
    element instanceof HTMLTextAreaElement ||
    element instanceof HTMLSelectElement
  )
}

type CliShellProviderProps = {
  projectId: string
  children: ReactNode
}

export function CliShellProvider({ projectId, children }: CliShellProviderProps) {
  const t = useT()
  const { account, isLoading: isAccountLoading } = useAuth()
  const { project } = useProject(projectId)
  const organizationId = project?.teamId
  const consoleAccount = account as ConsoleAccountCache | undefined
  const { isOpen: open, setIsOpen: setOpen } = useCliShellOpen(consoleAccount)
  const { heightPx, setHeightPx } = useCliShellHeight(consoleAccount)
  const { history: commandHistory, persistHistory: persistCommandHistory } =
    useCliShellHistory(consoleAccount, projectId)
  const { persistSessions, flushPersistSessions } = useCliShellSessionsPrefs(
    consoleAccount,
    projectId,
  )
  const height = clampCliShellHeightPx(heightPx)
  /** Mount the terminal panel immediately so it can warm up while collapsed. */
  const [panelEverOpened, setPanelEverOpened] = useState(true)

  const [status, setStatus] = useState<CliShellStatus>('idle')
  const [runningSessionId, setRunningSessionId] = useState<string | null>(null)
  const [bootstrapError, setBootstrapError] = useState<string | null>(null)
  const [terminalSearchOpen, setTerminalSearchOpenState] = useState(false)
  const terminalSearchOpenRef = useRef(terminalSearchOpen)
  terminalSearchOpenRef.current = terminalSearchOpen
  const [terminalSearchResults, setTerminalSearchResults] =
    useState<CliTerminalSearchResults | null>(null)
  const [terminalSearchQuery, setTerminalSearchQuery] = useState('')
  const [searchSessionIds, setSearchSessionIds] = useState<string[]>([])
  const searchSessionIdsRef = useRef(searchSessionIds)
  searchSessionIdsRef.current = searchSessionIds
  const searchResultsBySessionRef = useRef(
    new Map<string, CliTerminalSearchResults>(),
  )
  const [searchCaseSensitive, setSearchCaseSensitive] = useState(false)
  // One shared bootstrap so session ids stay consistent across state slices.
  const [sessionBootstrap] = useState(createInitialCliShellSessionState)
  const [sessions, setSessions] = useState<CliShellSession[]>(
    sessionBootstrap.sessions,
  )
  const [activeSessionId, setActiveSessionIdState] = useState<string>(
    sessionBootstrap.activeSessionId,
  )
  const [splitPaneSessionIds, setSplitPaneSessionIds] = useState<string[]>([])
  const [focusedSessionId, setFocusedSessionId] = useState<string>(
    sessionBootstrap.activeSessionId,
  )
  const isRunning = runningSessionId !== null
  const runningSessionIdRef = useRef<string | null>(null)
  runningSessionIdRef.current = runningSessionId
  const commandHistoryRef = useRef(commandHistory)
  commandHistoryRef.current = commandHistory
  const suggestionCommandsRef = useRef<readonly string[]>(CLI_SHELL_TRY_COMMANDS)
  const runDismissedRef = useRef(false)
  const lastSearchQueryRef = useRef('')
  const lastSearchSessionIdRef = useRef<string | null>(null)
  const lastGlobalSearchIndexRef = useRef(-1)
  const suppressSearchFocusRerunRef = useRef(false)
  const suppressSearchReportRef = useRef(false)
  const sessionsHydratedForProjectRef = useRef<string | null>(null)
  const sessionsDirtyRef = useRef(false)
  /** Skip one persist after hydrating saved prefs (same-commit state is still stale). */
  const suppressSessionsPersistRef = useRef(false)
  const previousProjectIdRef = useRef<string | null>(null)
  const lastPersistedSessionsRef = useRef('')
  const persistSessionsRef = useRef(persistSessions)
  persistSessionsRef.current = persistSessions
  const flushPersistSessionsRef = useRef(flushPersistSessions)
  flushPersistSessionsRef.current = flushPersistSessions

  const containerRef = useRef<CliShellContainer | null>(null)
  const terminalApisRef = useRef<Map<string, CliTerminalApi>>(new Map())
  const terminalContentReadyRef = useRef<Set<string>>(new Set())
  const welcomeInitInProgressRef = useRef<Set<string>>(new Set())
  const activeSessionIdRef = useRef(activeSessionId)
  activeSessionIdRef.current = activeSessionId
  const sessionsRef = useRef(sessions)
  sessionsRef.current = sessions
  const splitPaneSessionIdsRef = useRef(splitPaneSessionIds)
  splitPaneSessionIdsRef.current = splitPaneSessionIds
  const focusedSessionIdRef = useRef(focusedSessionId)
  focusedSessionIdRef.current = focusedSessionId
  const pendingBootstrapMessagesRef = useRef<string[]>([])
  const bootstrapPromiseRef = useRef<Promise<CliShellContainer> | null>(null)
  const bootstrapPendingRef = useRef<string | null>(null)
  const bootstrapTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const bootstrapLastUpdateRef = useRef(0)
  const bootstrapSilentRef = useRef(false)
  const bootstrapReadyAnnouncedRef = useRef(false)
  const bootstrapLastWrittenRef = useRef<string | null>(null)
  /** Bumped when bootstrap finishes or resets so throttled line timers are ignored. */
  const bootstrapEpochRef = useRef(0)
  /** Incremented on each bootstrap attempt so stale async work is ignored after retry. */
  const bootstrapRunIdRef = useRef(0)
  const authInitializedRef = useRef(false)
  const authInitInFlightRef = useRef<Promise<void> | null>(null)
  const heightBeforeFullscreenRef = useRef<number | null>(null)

  const [fullscreen, setFullscreen] = useState(false)
  const openRef = useRef(open)
  openRef.current = open
  const statusRef = useRef(status)
  statusRef.current = status
  const syncCliShellPromptOnOpenRef = useRef<() => void>(() => {})

  const BOOTSTRAP_LINE_MIN_INTERVAL_MS = 250

  const getTerminalApi = useCallback((sessionId?: string) => {
    const id = sessionId ?? focusedSessionIdRef.current
    return terminalApisRef.current.get(id) ?? null
  }, [])

  const showInputPromptIfIdle = useCallback(
    (sessionId?: string, force = false) => {
      const id = sessionId ?? focusedSessionIdRef.current
      if (runningSessionIdRef.current === id) return

      const api = getTerminalApi(sessionId)
      if (!api) return

      if (api.prepareInputLine) {
        api.prepareInputLine(force)
        return
      }

      api.showInputPrompt?.()
    },
    [getTerminalApi],
  )

  const writeWelcome = useCallback(async (api: CliTerminalApi) => {
    for (const line of createCliShellWelcomeLines()) {
      if (line.type === 'suggestions') {
        await writeCliShellSuggestions(api, line.commands)
      } else {
        writeCliShellLine(api, line)
      }
    }
  }, [])

  const flushPendingBootstrapMessages = useCallback((sessionId: string) => {
    if (sessionId !== activeSessionIdRef.current) return false

    const api = terminalApisRef.current.get(sessionId)
    if (!api) return false

    let shouldShowPrompt = bootstrapReadyAnnouncedRef.current

    for (const message of pendingBootstrapMessagesRef.current) {
      if (
        message !== CLI_BOOTSTRAP_READY_MESSAGE &&
        bootstrapReadyAnnouncedRef.current
      ) {
        continue
      }
      if (
        message === CLI_BOOTSTRAP_READY_MESSAGE &&
        bootstrapReadyAnnouncedRef.current
      ) {
        continue
      }
      if (message === CLI_BOOTSTRAP_READY_MESSAGE) {
        bootstrapReadyAnnouncedRef.current = true
        shouldShowPrompt = true
      }
      api.writeln(`${CLI_TERMINAL_MUTED}${message}${CLI_TERMINAL_RESET}`)
      bootstrapLastWrittenRef.current = message
    }
    pendingBootstrapMessagesRef.current = []

    return shouldShowPrompt
  }, [])

  const registerTerminal = useCallback((sessionId: string, api: CliTerminalApi) => {
    terminalApisRef.current.set(sessionId, api)
    requestAnimationFrame(() => {
      syncCliShellPromptOnOpenRef.current()
    })
  }, [])

  const writeSessionWelcome = useCallback(
    async (sessionId: string): Promise<boolean> => {
      const api = terminalApisRef.current.get(sessionId)
      if (!api) return false

      if (terminalContentReadyRef.current.has(sessionId)) {
        return true
      }

      if (welcomeInitInProgressRef.current.has(sessionId)) {
        return false
      }

      welcomeInitInProgressRef.current.add(sessionId)

      try {
        api.resetForWelcome?.()
        await writeWelcome(api)

        let shouldShowPrompt = false
        if (sessionId === activeSessionIdRef.current) {
          shouldShowPrompt = flushPendingBootstrapMessages(sessionId)
          if (
            !shouldShowPrompt &&
            statusRef.current === 'ready' &&
            containerRef.current
          ) {
            if (!bootstrapReadyAnnouncedRef.current) {
              bootstrapReadyAnnouncedRef.current = true
            }
            shouldShowPrompt = true
          }
        }

        api.markWelcomeComplete?.()

        const isActiveRoot = sessionId === activeSessionIdRef.current
        if (isActiveRoot && openRef.current) {
          if (shouldShowPrompt || statusRef.current === 'bootstrapping') {
            showInputPromptIfIdle(sessionId, true)
          }
        } else if (!isActiveRoot) {
          showInputPromptIfIdle(sessionId)
        }

        terminalContentReadyRef.current.add(sessionId)
        return true
      } finally {
        welcomeInitInProgressRef.current.delete(sessionId)
      }
    },
    [flushPendingBootstrapMessages, showInputPromptIfIdle, writeWelcome],
  )

  const unregisterTerminal = useCallback((sessionId: string) => {
    terminalApisRef.current.delete(sessionId)
    terminalContentReadyRef.current.delete(sessionId)
    welcomeInitInProgressRef.current.delete(sessionId)
  }, [])

  const writeBootstrapMessage = useCallback((text: string) => {
    if (
      text === CLI_BOOTSTRAP_READY_MESSAGE &&
      bootstrapReadyAnnouncedRef.current
    ) {
      return
    }
    if (
      text !== CLI_BOOTSTRAP_READY_MESSAGE &&
      bootstrapReadyAnnouncedRef.current
    ) {
      return
    }
    if (bootstrapLastWrittenRef.current === text) {
      return
    }

    const activeId = activeSessionIdRef.current
    const api = getTerminalApi()
    if (
      api &&
      terminalContentReadyRef.current.has(activeId)
    ) {
      if (text === CLI_BOOTSTRAP_READY_MESSAGE) {
        bootstrapReadyAnnouncedRef.current = true
      }
      api.writeln(`${CLI_TERMINAL_MUTED}${text}${CLI_TERMINAL_RESET}`)
      bootstrapLastWrittenRef.current = text
      if (text === CLI_BOOTSTRAP_READY_MESSAGE && openRef.current) {
        showInputPromptIfIdle(activeId, true)
      }
      return
    }

    if (
      text === CLI_BOOTSTRAP_READY_MESSAGE &&
      pendingBootstrapMessagesRef.current.includes(CLI_BOOTSTRAP_READY_MESSAGE)
    ) {
      return
    }
    if (pendingBootstrapMessagesRef.current.includes(text)) {
      return
    }

    pendingBootstrapMessagesRef.current.push(text)
  }, [getTerminalApi, showInputPromptIfIdle])

  const scheduleBootstrapLine = useCallback(
    (text: string) => {
      if (
        text !== CLI_BOOTSTRAP_READY_MESSAGE &&
        bootstrapReadyAnnouncedRef.current
      ) {
        return
      }

      const scheduleEpoch = bootstrapEpochRef.current
      bootstrapPendingRef.current = text

      const flush = () => {
        if (scheduleEpoch !== bootstrapEpochRef.current) return

        bootstrapTimerRef.current = null
        const pending = bootstrapPendingRef.current
        if (pending !== null) {
          writeBootstrapMessage(pending)
          bootstrapPendingRef.current = null
          bootstrapLastUpdateRef.current = Date.now()
        }
      }

      if (bootstrapTimerRef.current !== null) return

      const elapsed = Date.now() - bootstrapLastUpdateRef.current
      if (elapsed >= BOOTSTRAP_LINE_MIN_INTERVAL_MS) {
        flush()
        return
      }

      bootstrapTimerRef.current = setTimeout(
        flush,
        BOOTSTRAP_LINE_MIN_INTERVAL_MS - elapsed,
      )
    },
    [writeBootstrapMessage],
  )

  const finalizeBootstrapLine = useCallback(
    (text: string) => {
      bootstrapEpochRef.current += 1
      if (bootstrapTimerRef.current !== null) {
        clearTimeout(bootstrapTimerRef.current)
        bootstrapTimerRef.current = null
      }
      bootstrapPendingRef.current = null
      writeBootstrapMessage(text)
    },
    [writeBootstrapMessage],
  )

  const resetBootstrapLine = useCallback(() => {
    bootstrapEpochRef.current += 1
    if (bootstrapTimerRef.current !== null) {
      clearTimeout(bootstrapTimerRef.current)
      bootstrapTimerRef.current = null
    }
    bootstrapPendingRef.current = null
  }, [])

  const writeStderrLine = useCallback(
    (
      text: string,
      options?: { showPromptAfter?: boolean; sessionId?: string },
    ) => {
      writeCliShellLine(
        getTerminalApi(options?.sessionId),
        { type: 'stderr', text },
        options,
      )
    },
    [getTerminalApi],
  )

  const writeSystemLine = useCallback(
    (text: string, sessionId?: string) => {
      writeCliShellLine(
        getTerminalApi(sessionId),
        { type: 'system', text },
        { showPromptAfter: runningSessionIdRef.current === null },
      )
    },
    [getTerminalApi],
  )

  const setHeight = useCallback(
    (next: number) => {
      setHeightPx(clampCliShellHeightPx(next))
    },
    [setHeightPx],
  )

  const exitFullscreen = useCallback(() => {
    setFullscreen(false)
    if (heightBeforeFullscreenRef.current !== null) {
      setHeight(heightBeforeFullscreenRef.current)
      heightBeforeFullscreenRef.current = null
    }
  }, [setHeight])

  const enterFullscreen = useCallback(() => {
    setOpen(true)
    heightBeforeFullscreenRef.current = height
    setFullscreen(true)
  }, [height, setOpen])

  const toggleFullscreen = useCallback(() => {
    if (fullscreen) {
      exitFullscreen()
      return
    }
    enterFullscreen()
  }, [enterFullscreen, exitFullscreen, fullscreen])

  useEffect(() => {
    if (open) {
      setPanelEverOpened(true)
    }
  }, [open])

  const syncCliShellPromptOnOpen = useCallback(() => {
    if (!openRef.current || runningSessionIdRef.current !== null) return

    if (
      statusRef.current === 'ready' &&
      containerRef.current &&
      !bootstrapReadyAnnouncedRef.current
    ) {
      finalizeBootstrapLine(CLI_BOOTSTRAP_READY_MESSAGE)
    }

    const activeId = activeSessionIdRef.current
    if (!terminalContentReadyRef.current.has(activeId)) return
    if (!bootstrapReadyAnnouncedRef.current) return

    showInputPromptIfIdle(activeId, true)
  }, [finalizeBootstrapLine, showInputPromptIfIdle])

  syncCliShellPromptOnOpenRef.current = syncCliShellPromptOnOpen

  useEffect(() => {
    if (!open) return

    syncCliShellPromptOnOpen()
    const timer = window.setTimeout(
      syncCliShellPromptOnOpen,
      CLI_SHELL_COLLAPSE_MS,
    )

    return () => clearTimeout(timer)
  }, [open, status, syncCliShellPromptOnOpen])

  useEffect(() => {
    if (!open && fullscreen) {
      heightBeforeFullscreenRef.current = null
      setFullscreen(false)
    }
  }, [fullscreen, open])

  useEffect(() => {
    if (open) return
    flushPersistSessionsRef.current()
  }, [open])

  const initializeCliAuth = useCallback(
    async (container: CliShellContainer): Promise<void> => {
      if (authInitializedRef.current || isAccountLoading) return
      if (authInitInFlightRef.current) {
        await authInitInFlightRef.current
        return
      }

      const accountUser = account as Models.User | undefined
      const email = accountUser?.email?.trim()
      if (!email) return

      const initPromise = (async () => {
        const auth = await resolveConsoleCliAuth()
        if (!auth) {
          throw new Error(
            'No active console session. Sign in again to use the Appwrite CLI.',
          )
        }

        const projectEndpoint = getProjectApiEndpoint(projectId)
        const consoleEndpoint = getBaseEndpoint()

        if (auth.mode === 'browser-proxy') {
          installCliFetchBridge([consoleEndpoint, projectEndpoint])
        } else {
          removeCliFetchBridge()
        }

        syncCliAuthFiles(container.vfs, {
          projectId,
          projectEndpoint,
          consoleEndpoint,
          email,
          auth,
        })

        syncCliProjectConfig(container.vfs, {
          projectId,
          projectEndpoint,
          organizationId,
        })

        authInitializedRef.current = true
      })()

      authInitInFlightRef.current = initPromise
      try {
        await initPromise
      } finally {
        if (authInitInFlightRef.current === initPromise) {
          authInitInFlightRef.current = null
        }
      }
    },
    [account, isAccountLoading, organizationId, projectId],
  )

  const ensureRuntime = useCallback(
    async (options?: { silent?: boolean }): Promise<CliShellContainer> => {
      const projectBootstrap = getProjectBootstrapState(projectId)

      if (containerRef.current) {
        return containerRef.current
      }
      if (projectBootstrap.container) {
        containerRef.current = projectBootstrap.container
        setStatus('ready')
        setBootstrapError(null)
        return projectBootstrap.container
      }

      const requestedSilent = options?.silent ?? false
      if (bootstrapPromiseRef.current ?? projectBootstrap.promise) {
        const activePromise =
          bootstrapPromiseRef.current ?? projectBootstrap.promise!
        if (!requestedSilent && bootstrapSilentRef.current) {
          bootstrapSilentRef.current = false
          setBootstrapError(null)
          setStatus('bootstrapping')
          scheduleBootstrapLine('Setting up browser shell runtime...')
        }
        return activePromise
      }

      const silent = requestedSilent
      bootstrapSilentRef.current = silent

      const projectEndpoint = getProjectApiEndpoint(projectId)
      const consoleEndpoint = getBaseEndpoint()

      setBootstrapError(null)
      if (!silent) {
        setStatus('bootstrapping')
        scheduleBootstrapLine('Setting up browser shell runtime...')
      } else {
        setStatus('bootstrapping')
      }

      const runId = ++bootstrapRunIdRef.current

      const promise = bootstrapCliRuntime(
        {
          projectId,
          projectEndpoint,
          consoleEndpoint,
          organizationId,
        },
        (message) => {
          if (bootstrapSilentRef.current) return
          scheduleBootstrapLine(message)
        },
      )
        .then(async (container) => {
          if (runId !== bootstrapRunIdRef.current) {
            return container
          }

          containerRef.current = container
          projectBootstrap.container = container
          projectBootstrap.promise = null

          if (!bootstrapSilentRef.current) {
            try {
              await initializeCliAuth(container)
              setBootstrapError(null)
            } catch (error: unknown) {
              const message =
                error instanceof Error
                  ? error.message
                  : 'Failed to configure Appwrite CLI session.'
              setBootstrapError(message)
              resetBootstrapLine()
              writeStderrLine(message)
            }
          }

          setStatus('ready')
          if (!bootstrapSilentRef.current) {
            finalizeBootstrapLine(CLI_BOOTSTRAP_READY_MESSAGE)
          }
          return container
        })
        .catch((error: unknown) => {
          if (runId !== bootstrapRunIdRef.current) {
            throw error
          }

          projectBootstrap.container = null
          projectBootstrap.promise = null
          const message =
            error instanceof Error ? error.message : 'Failed to start CLI shell.'
          setStatus('error')
          setBootstrapError(message)
          if (!bootstrapSilentRef.current) {
            resetBootstrapLine()
          }
          throw error
        })
        .finally(() => {
          if (runId !== bootstrapRunIdRef.current) return
          bootstrapPromiseRef.current = null
          bootstrapSilentRef.current = false
        })

      bootstrapPromiseRef.current = promise
      projectBootstrap.promise = promise
      return promise
    },
    [
      finalizeBootstrapLine,
      initializeCliAuth,
      organizationId,
      projectId,
      resetBootstrapLine,
      scheduleBootstrapLine,
      writeStderrLine,
    ],
  )

  useEffect(() => {
    const container = containerRef.current
    if (!container || !organizationId) return

    syncCliProjectConfig(container.vfs, {
      projectId,
      projectEndpoint: getProjectApiEndpoint(projectId),
      organizationId,
    })
  }, [organizationId, projectId])

  useEffect(() => {
    if (!open) return
    const container = containerRef.current
    if (!container || isAccountLoading || authInitializedRef.current) return
    void initializeCliAuth(container)
      .then(() => setBootstrapError(null))
      .catch((error: unknown) => {
        const message =
          error instanceof Error
            ? error.message
            : 'Failed to configure Appwrite CLI session.'
        setBootstrapError(message)
      })
  }, [open, account, initializeCliAuth, isAccountLoading])

  const retryBootstrap = useCallback(() => {
    bootstrapRunIdRef.current += 1
    containerRef.current = null
    bootstrapPromiseRef.current = null
    bootstrapReadyAnnouncedRef.current = false
    authInitializedRef.current = false
    authInitInFlightRef.current = null
    const projectBootstrap = getProjectBootstrapState(projectId)
    projectBootstrap.container = null
    projectBootstrap.promise = null
    setBootstrapError(null)
    resetBootstrapLine()
    setStatus('idle')
    removeCliFetchBridge()
    if (open) {
      void ensureRuntime({ silent: false }).catch(() => {})
    }
  }, [ensureRuntime, open, projectId, resetBootstrapLine])

  useEffect(() => {
    const handleTerminalCacheCleared = () => {
      retryBootstrap()
    }
    window.addEventListener(CLI_TERMINAL_CACHE_CLEARED, handleTerminalCacheCleared)
    return () => {
      window.removeEventListener(
        CLI_TERMINAL_CACHE_CLEARED,
        handleTerminalCacheCleared,
      )
    }
  }, [retryBootstrap])

  useEffect(() => {
    if (!open) return
    void ensureRuntime({ silent: false }).catch(() => {})
  }, [ensureRuntime, open])

  useEffect(() => {
    if (typeof window === 'undefined') return
    if (!organizationId || isAccountLoading) return

    let cancelled = false
    let idleId: number | undefined
    let timeoutId: ReturnType<typeof globalThis.setTimeout> | undefined

    const preloadRuntime = () => {
      if (cancelled) return
      void ensureRuntime({ silent: true }).catch(() => {})
    }

    if (typeof window.requestIdleCallback === 'function') {
      idleId = window.requestIdleCallback(preloadRuntime, { timeout: 4000 })
    } else {
      timeoutId = globalThis.setTimeout(preloadRuntime, 2000)
    }

    return () => {
      cancelled = true
      if (idleId !== undefined) {
        window.cancelIdleCallback(idleId)
      }
      if (timeoutId !== undefined) {
        globalThis.clearTimeout(timeoutId)
      }
    }
  }, [ensureRuntime, isAccountLoading, organizationId])

  useEffect(() => {
    const previousProjectId = previousProjectIdRef.current
    previousProjectIdRef.current = projectId
    if (previousProjectId === null || previousProjectId === projectId) {
      return
    }

    containerRef.current = null
    bootstrapPromiseRef.current = null
    bootstrapReadyAnnouncedRef.current = false
    bootstrapLastWrittenRef.current = null
    authInitializedRef.current = false
    authInitInFlightRef.current = null
    deleteProjectBootstrapState(projectId)
    setStatus('idle')
    setBootstrapError(null)
    resetBootstrapLine()
    runDismissedRef.current = false
    runningSessionIdRef.current = null
    setRunningSessionId(null)
    terminalContentReadyRef.current.clear()
    welcomeInitInProgressRef.current.clear()
    terminalApisRef.current.clear()
    pendingBootstrapMessagesRef.current = []
    suggestionCommandsRef.current = CLI_SHELL_TRY_COMMANDS
    sessionsHydratedForProjectRef.current = null
    sessionsDirtyRef.current = false
    suppressSessionsPersistRef.current = false
    lastPersistedSessionsRef.current = ''
    const nextState = createInitialCliShellSessionState()
    setSessions(nextState.sessions)
    setActiveSessionIdState(nextState.activeSessionId)
    setFocusedSessionId(nextState.activeSessionId)
    setSplitPaneSessionIds([])
    removeCliFetchBridge()
  }, [projectId, resetBootstrapLine])

  useEffect(() => {
    if (!consoleAccount) return
    if (sessionsHydratedForProjectRef.current === projectId) return
    if (sessionsDirtyRef.current) {
      // In-memory edits already applied for this project; treat them as baseline.
      const persistedSplitPaneIds =
        splitPaneSessionIdsRef.current.length > 1
          ? splitPaneSessionIdsRef.current
          : undefined
      lastPersistedSessionsRef.current = serializeCliShellSessionsState({
        sessions: sessionsRef.current,
        activeSessionId: activeSessionIdRef.current,
        splitPaneSessionIds: persistedSplitPaneIds,
      })
      sessionsHydratedForProjectRef.current = projectId
      suppressSessionsPersistRef.current = false
      return
    }

    const saved = parseCliShellSessions(
      consoleAccount.prefs as UserPrefs | undefined,
      projectId,
    )
    if (saved) {
      setSessions(saved.sessions)
      setActiveSessionIdState(saved.activeSessionId)
      setFocusedSessionId(saved.activeSessionId)
      setSplitPaneSessionIds(saved.splitPaneSessionIds ?? [])
      lastPersistedSessionsRef.current = serializeCliShellSessionsState(saved)
    } else {
      // No saved prefs: mark the in-memory default as baseline so we do not
      // write until the user actually changes sessions.
      const persistedSplitPaneIds =
        splitPaneSessionIdsRef.current.length > 1
          ? splitPaneSessionIdsRef.current
          : undefined
      lastPersistedSessionsRef.current = serializeCliShellSessionsState({
        sessions: sessionsRef.current,
        activeSessionId: activeSessionIdRef.current,
        splitPaneSessionIds: persistedSplitPaneIds,
      })
    }

    // Keep suppress set across React Strict Mode's effect double-invoke so the
    // persist effect cannot write ephemeral bootstrap sessions after the first
    // invoke consumed a one-shot flag.
    suppressSessionsPersistRef.current = true
    sessionsHydratedForProjectRef.current = projectId
  }, [consoleAccount, projectId])

  useEffect(() => {
    if (sessionsHydratedForProjectRef.current !== projectId) return

    const persistedSplitPaneIds =
      splitPaneSessionIds.length > 1 ? splitPaneSessionIds : undefined
    const payload = serializeCliShellSessionsState({
      sessions,
      activeSessionId,
      splitPaneSessionIds: persistedSplitPaneIds,
    })
    if (lastPersistedSessionsRef.current === payload) {
      // State has caught up to the hydration baseline (or a prior write).
      suppressSessionsPersistRef.current = false
      return
    }

    // After hydration, React may still flush once with bootstrap session ids
    // (Strict Mode remount / batched setState). Never clobber saved prefs with
    // that ephemeral state; wait until session ids match the baseline (or the
    // user edits, which updates lastPersisted via a real persist).
    if (suppressSessionsPersistRef.current) {
      try {
        const baseline = JSON.parse(
          lastPersistedSessionsRef.current || 'null',
        ) as { sessions?: { id: string }[] } | null
        const baselineIds = new Set(
          (baseline?.sessions ?? []).map((session) => session.id),
        )
        const stateIds = sessions.map((session) => session.id)
        const sameSessionSet =
          baselineIds.size > 0 &&
          stateIds.length === baselineIds.size &&
          stateIds.every((id) => baselineIds.has(id))
        if (!sameSessionSet) {
          return
        }
      } catch {
        return
      }
      suppressSessionsPersistRef.current = false
      // Session set matches baseline; if only active/split changed, fall through
      // and persist. If payload somehow still equals after normalize, bail.
      if (lastPersistedSessionsRef.current === payload) return
    }

    lastPersistedSessionsRef.current = payload
    persistSessionsRef.current({
      sessions,
      activeSessionId,
      splitPaneSessionIds: persistedSplitPaneIds,
    })
  }, [activeSessionId, projectId, sessions, splitPaneSessionIds])

  useEffect(() => {
    return () => {
      removeCliFetchBridge()
    }
  }, [])

  const clearOutput = useCallback(
    (sessionId?: string) => {
      const api = getTerminalApi(sessionId)
      if (api?.clearScreen) {
        api.clearScreen()
        return
      }
      api?.clear()
      showInputPromptIfIdle(sessionId)
    },
    [getTerminalApi, showInputPromptIfIdle],
  )

  const completeTab = useCallback(
    (
      input: string,
      cursor: number,
      listOnly = false,
      sessionId?: string,
    ) => {
      const outcome = tabComplete(input, cursor, {
        vfs: containerRef.current?.vfs ?? null,
      }, listOnly)

      if (outcome.kind === 'none') return null

      if (outcome.kind === 'list') {
        const api = getTerminalApi(sessionId)
        if (api) {
          api.writeln('')
        }
        writeSystemLine(outcome.matches.join('  '), sessionId)
        return { input, cursor }
      }

      return applyTabCompletion(input, cursor, outcome)
    },
    [getTerminalApi, writeSystemLine],
  )

  const focusSession = useCallback(
    (sessionId: string, options?: { force?: boolean }) => {
      const api = terminalApisRef.current.get(sessionId)
      if (!api) return false
      if (terminalSearchOpen) return true
      requestAnimationFrame(() => {
        if (
          !options?.force &&
          isEditablePageFocusTarget(document.activeElement)
        ) {
          return
        }
        if (api.focusInputLine) {
          api.focusInputLine()
          return
        }
        api.focus()
      })
      return true
    },
    [terminalSearchOpen],
  )

  const prevCliOpenRef = useRef<boolean | null>(null)

  useEffect(() => {
    const prevOpen = prevCliOpenRef.current
    prevCliOpenRef.current = open

    if (prevOpen === null) {
      // Already open on first mount: prepare terminal without stealing page focus.
      return
    }

    if (!open || prevOpen || terminalSearchOpen) return

    const sessionId = focusedSessionIdRef.current
    const timer = window.setTimeout(() => {
      focusSession(sessionId, { force: true })
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new Event('resize'))
      }
    }, CLI_SHELL_COLLAPSE_MS)
    return () => window.clearTimeout(timer)
  }, [focusSession, open, terminalSearchOpen])

  const setActiveSessionId = useCallback((sessionId: string) => {
    setActiveSessionIdState(sessionId)
    setFocusedSessionId(sessionId)
  }, [])

  const selectSession = useCallback(
    (sessionId: string) => {
      const rootId = getCliShellSessionRootId(sessionId, sessionsRef.current)
      setActiveSessionIdState(rootId)
      setFocusedSessionId(sessionId)
      focusSession(sessionId, { force: true })
    },
    [focusSession],
  )

  const focusSessionPane = useCallback(
    (sessionId: string) => {
      setFocusedSessionId(sessionId)
      focusSession(sessionId, { force: true })
    },
    [focusSession],
  )

  const splitSession = useCallback(
    (sessionId: string) => {
      sessionsDirtyRef.current = true
      const rootId = getCliShellSessionRootId(
        sessionId,
        sessionsRef.current,
      )
      const nextSessionId = createCliShellSessionId()
      let added = false

      setSessions((prev) => {
        if (prev.length >= MAX_CLI_SHELL_SESSIONS) {
          toast.error(
            `${t('Maximum')} ${MAX_CLI_SHELL_SESSIONS} ${t('terminal sessions.')}`,
          )
          return prev
        }
        added = true
        const nextSession: CliShellSession = {
          id: nextSessionId,
          name: nextCliShellSessionName(prev),
          parentSessionId: rootId,
        }
        return [...prev, nextSession]
      })

      if (!added) return

      setSplitPaneSessionIds((prev) => {
        const panes =
          prev.length > 1
            ? [...prev]
            : sessionsRef.current.some((session) => session.id === sessionId)
              ? [sessionId]
              : [rootId]

        if (!panes.includes(sessionId)) {
          if (!panes.includes(rootId)) {
            panes.unshift(rootId)
          }
          if (sessionId !== rootId) {
            const rootIndex = panes.indexOf(rootId)
            panes.splice(rootIndex + 1, 0, sessionId)
          }
        }

        const index = panes.indexOf(sessionId)
        const next = [...panes]
        next.splice(index >= 0 ? index + 1 : next.length, 0, nextSessionId)
        return next
      })

      setActiveSessionIdState(rootId)
      setFocusedSessionId(nextSessionId)

      const focusNewSession = (attempt = 0) => {
        if (focusSession(nextSessionId, { force: true }) || attempt >= 30) return
        requestAnimationFrame(() => focusNewSession(attempt + 1))
      }
      requestAnimationFrame(() => focusNewSession())
    },
    [focusSession, t],
  )

  const renameSession = useCallback((sessionId: string, name: string) => {
    const trimmed = name.trim().slice(0, MAX_CLI_SHELL_SESSION_NAME_LENGTH)
    if (!trimmed) return
    sessionsDirtyRef.current = true
    setSessions((prev) =>
      prev.map((session) =>
        session.id === sessionId ? { ...session, name: trimmed } : session,
      ),
    )
  }, [])

  const reorderRootSessions = useCallback(
    (fromIndex: number, toIndex: number) => {
      sessionsDirtyRef.current = true
      setSessions((prev) =>
        reorderCliShellRootSessions(prev, fromIndex, toIndex),
      )
    },
    [],
  )

  const createSession = useCallback(() => {
    sessionsDirtyRef.current = true
    const nextSessionId = createCliShellSessionId()

    setSessions((prev) => {
      if (prev.length >= MAX_CLI_SHELL_SESSIONS) {
        toast.error(
          `${t('Maximum')} ${MAX_CLI_SHELL_SESSIONS} ${t('terminal sessions.')}`,
        )
        return prev
      }
      const nextSession: CliShellSession = {
        id: nextSessionId,
        name: nextCliShellSessionName(prev),
      }
      return [...prev, nextSession]
    })
    setActiveSessionIdState(nextSessionId)
    setFocusedSessionId(nextSessionId)

    const focusNewSession = (attempt = 0) => {
      if (focusSession(nextSessionId, { force: true }) || attempt >= 30) return
      requestAnimationFrame(() => focusNewSession(attempt + 1))
    }
    requestAnimationFrame(() => focusNewSession())
  }, [focusSession, t])

  const removeSession = useCallback((sessionId: string) => {
    sessionsDirtyRef.current = true
    const toRemove = collectCliShellSessionsToRemove(
      sessionId,
      sessionsRef.current,
    )
    if (sessionsRef.current.length - toRemove.length < 1) return

    const remaining = sessionsRef.current.filter(
      (session) => !toRemove.includes(session.id),
    )
    const nextActive =
      remaining.find((session) => !session.parentSessionId)?.id ??
      remaining[0]?.id ??
      ''
    const nextFocused = toRemove.includes(focusedSessionIdRef.current)
      ? remaining.find((session) => session.parentSessionId === nextActive)?.id ??
        nextActive
      : focusedSessionIdRef.current

    setSessions((prev) => {
      if (prev.length - toRemove.length < 1) return prev
      for (const id of toRemove) {
        terminalContentReadyRef.current.delete(id)
        welcomeInitInProgressRef.current.delete(id)
        terminalApisRef.current.delete(id)
      }
      return prev.filter((session) => !toRemove.includes(session.id))
    })

    setActiveSessionIdState(nextActive)
    setFocusedSessionId(nextFocused)
    setSplitPaneSessionIds((prev) =>
      normalizeCliShellSplitPaneIds(
        prev.filter((id) => !toRemove.includes(id)),
        remaining,
      ),
    )
  }, [])

  const runCommand = useCallback(
    async (rawCommand: string, sessionId?: string) => {
      const targetSessionId = sessionId ?? activeSessionIdRef.current
      const trimmed = rawCommand.trim()
      if (!trimmed) return

      // Shell builtin: close this terminal (or minimize the panel if last).
      if (/^exit(?:\s+\d+)?$/i.test(trimmed)) {
        const toRemove = collectCliShellSessionsToRemove(
          targetSessionId,
          sessionsRef.current,
        )
        if (sessionsRef.current.length - toRemove.length < 1) {
          heightBeforeFullscreenRef.current = null
          setFullscreen(false)
          setOpen(false)
          return
        }
        removeSession(targetSessionId)
        return
      }

      const blockedMessage = getBlockedCliCommandMessage(rawCommand)
      if (blockedMessage) {
        const lines = blockedMessage.split('\n')
        lines.forEach((line, index) => {
          writeStderrLine(line, {
            showPromptAfter: index === lines.length - 1,
            sessionId: targetSessionId,
          })
        })
        return
      }

      const prepared = prepareCliCommand(rawCommand)
      if (prepared.type === 'empty') return

      if (prepared.type === 'unsupported') {
        prepared.message.split('\n').forEach((line, index, lines) => {
          writeStderrLine(line, {
            showPromptAfter: index === lines.length - 1,
            sessionId: targetSessionId,
          })
        })
        return
      }

      const command = prepared.args

      let container: CliShellContainer
      try {
        container = await ensureRuntime()
      } catch (error: unknown) {
        const message =
          error instanceof Error ? error.message : 'CLI shell is not ready.'
        if (!bootstrapError) {
          writeStderrLine(message, {
            showPromptAfter: true,
            sessionId: targetSessionId,
          })
        }
        return
      }

      if (isAppwriteCliCommand(rawCommand) && !authInitializedRef.current) {
        try {
          await initializeCliAuth(container)
        } catch (error: unknown) {
          const message =
            error instanceof Error
              ? error.message
              : 'Failed to configure Appwrite CLI session.'
          setBootstrapError(message)
        }
      }

      if (isAppwriteCliCommand(rawCommand) && !authInitializedRef.current) {
        writeStderrLine(
          bootstrapError ??
            'Appwrite CLI session is not ready. Retry setup or refresh the page.',
          { showPromptAfter: true, sessionId: targetSessionId },
        )
        return
      }

      runDismissedRef.current = false
      runningSessionIdRef.current = targetSessionId
      setRunningSessionId(targetSessionId)
      setStatus('running')

      let streamedStdout = false
      let streamedStderr = false
      let stdoutAccum = ''
      let stderrAccum = ''
      const stdoutLinkifier = createTerminalOutputLinkifier()
      const getOutputApi = () =>
        terminalApisRef.current.get(targetSessionId) ?? null

      const runOptions: CliShellRunOptions = {
        cwd: CLI_PROJECT_CWD,
        onStdout: (chunk) => {
          if (!chunk) return
          streamedStdout = true
          stdoutAccum += chunk
          stdoutLinkifier.write(getOutputApi(), chunk)
        },
        onStderr: (chunk) => {
          if (!chunk) return
          streamedStderr = true
          stderrAccum += chunk
        },
      }

      try {
        const result = await container.run(command, runOptions)

        if (result.stdout && !streamedStdout) {
          stdoutAccum = result.stdout
          writeCliTerminalRaw(getOutputApi(), result.stdout)
        }
        if (
          result.stderr &&
          !streamedStderr &&
          shouldWriteCliStderr(result.stdout, result.stderr)
        ) {
          stderrAccum = result.stderr
          writeCliTerminalRaw(getOutputApi(), result.stderr)
        }
        // Only when the command failed silently. The CLI reports its own
        // failures -- `✗ Error: ...` -- and announcing the exit code as well
        // reads as a second, separate thing having gone wrong.
        if (result.exitCode !== 0 && !result.stdout && !result.stderr) {
          writeStderrLine(`Command failed with exit code ${result.exitCode}.`, {
            sessionId: targetSessionId,
          })
        }
      } catch (error: unknown) {
        const message =
          error instanceof Error ? error.message : 'Command failed.'
        if (!message.startsWith('Process exited with code')) {
          writeStderrLine(message, { sessionId: targetSessionId })
        }
      } finally {
        stdoutLinkifier.flush(getOutputApi())
        if (shouldWriteCliStderr(stdoutAccum, stderrAccum)) {
          writeCliTerminalRaw(getOutputApi(), stderrAccum)
        }

        const dismissed = runDismissedRef.current
        if (dismissed) {
          runDismissedRef.current = false
        }

        runningSessionIdRef.current = null
        setRunningSessionId(null)
        setStatus(containerRef.current ? 'ready' : 'error')

        if (!dismissed) {
          showInputPromptIfIdle(targetSessionId)
        }
      }
    },
    [
      bootstrapError,
      ensureRuntime,
      initializeCliAuth,
      removeSession,
      setOpen,
      showInputPromptIfIdle,
      writeStderrLine,
    ],
  )

  const cancelRunning = useCallback(
    (sessionId?: string) => {
      const targetSessionId = sessionId ?? activeSessionIdRef.current
      if (runningSessionIdRef.current !== targetSessionId) return

      runDismissedRef.current = true
      runningSessionIdRef.current = null
      setRunningSessionId(null)
      setStatus(containerRef.current ? 'ready' : 'error')

      writeSystemLine('^C', targetSessionId)
      writeSystemLine('Command cancelled.', targetSessionId)
      showInputPromptIfIdle(targetSessionId)
    },
    [showInputPromptIfIdle, writeSystemLine],
  )

  const isSessionRunning = useCallback((sessionId: string) => {
    return runningSessionIdRef.current === sessionId
  }, [])

  const getCommandHistory = useCallback(() => commandHistoryRef.current, [])

  const getSuggestionCommands = useCallback(
    () => suggestionCommandsRef.current,
    [],
  )

  const resolveTerminalApiSessionId = useCallback(
    (preferredSessionId?: string | null) => {
      const candidates = [
        preferredSessionId,
        focusedSessionIdRef.current,
        activeSessionIdRef.current,
        ...getCliShellVisiblePaneSessionIds(
          activeSessionIdRef.current,
          sessionsRef.current,
          splitPaneSessionIdsRef.current,
        ),
        lastSearchSessionIdRef.current,
      ].filter((id): id is string => Boolean(id))

      for (const id of candidates) {
        if (terminalApisRef.current.has(id)) return id
      }

      return terminalApisRef.current.keys().next().value ?? null
    },
    [],
  )

  const getVisibleSearchPaneIds = useCallback(() => {
    return getCliShellVisiblePaneSessionIds(
      activeSessionIdRef.current,
      sessionsRef.current,
      splitPaneSessionIdsRef.current,
    )
  }, [])

  const getSearchTargetSessionIds = useCallback(
    (preferredSessionId?: string | null) => {
      const paneIds = getVisibleSearchPaneIds().filter((id) =>
        terminalApisRef.current.has(id),
      )

      if (paneIds.length > 1) {
        return paneIds
      }

      const singleId = resolveTerminalApiSessionId(preferredSessionId)
      return singleId ? [singleId] : []
    },
    [getVisibleSearchPaneIds, resolveTerminalApiSessionId],
  )

  const clearAllTerminalSearch = useCallback((exceptSessionId?: string) => {
    for (const [sessionId, api] of terminalApisRef.current) {
      if (exceptSessionId && sessionId === exceptSessionId) continue
      api.clearTerminalSearch?.()
    }
  }, [])

  const clearTerminalSearchState = useCallback(() => {
    lastSearchQueryRef.current = ''
    lastSearchSessionIdRef.current = null
    lastGlobalSearchIndexRef.current = -1
    searchResultsBySessionRef.current.clear()
    setTerminalSearchQuery('')
    setSearchSessionIds([])
    setTerminalSearchResults(null)
    clearAllTerminalSearch()
  }, [clearAllTerminalSearch])

  const setTerminalSearchOpen = useCallback(
    (open: boolean) => {
      setTerminalSearchOpenState(open)
      if (!open) {
        clearTerminalSearchState()
      }
    },
    [clearTerminalSearchState],
  )

  const reportSearchResults = useCallback(
    (sessionId: string, results: CliTerminalSearchResults) => {
      if (!terminalSearchOpenRef.current) return
      if (suppressSearchReportRef.current) return

      const targetIds = searchSessionIdsRef.current
      if (targetIds.length <= 1) {
        setTerminalSearchResults(results)
        if (results.resultCount > 0) {
          lastSearchSessionIdRef.current = sessionId
        }
        return
      }

      if (!targetIds.includes(sessionId)) return

      searchResultsBySessionRef.current.set(sessionId, results)
      const aggregated = aggregateCliTerminalSearchResults(
        targetIds,
        searchResultsBySessionRef.current,
        lastSearchSessionIdRef.current,
      )
      if (aggregated.activeSessionId) {
        lastSearchSessionIdRef.current = aggregated.activeSessionId
      }
      if (aggregated.resultIndex >= 0) {
        lastGlobalSearchIndexRef.current = aggregated.resultIndex
      }
      setTerminalSearchResults({
        resultIndex: aggregated.resultIndex,
        resultCount: aggregated.resultCount,
      })
    },
    [],
  )

  const goToSplitSearchMatch = useCallback(
    (
      sessionId: string,
      localIndex: number,
      query: string,
      globalIndex: number,
      totalCount: number,
    ) => {
      const api = terminalApisRef.current.get(sessionId)
      if (!api?.findFirstMatch?.(query)) return

      const sessionCount =
        searchResultsBySessionRef.current.get(sessionId)?.resultCount ?? 0
      if (sessionCount <= 0) return

      suppressSearchReportRef.current = true
      try {
        for (let step = 0; step < localIndex; step++) {
          if (!api.findNextMatch?.(query)) break
        }
      } finally {
        suppressSearchReportRef.current = false
      }

      searchResultsBySessionRef.current.set(sessionId, {
        resultIndex: localIndex,
        resultCount: sessionCount,
      })

      suppressSearchFocusRerunRef.current = true
      focusSessionPane(sessionId)
      suppressSearchFocusRerunRef.current = false

      lastSearchSessionIdRef.current = sessionId
      lastGlobalSearchIndexRef.current = globalIndex
      setTerminalSearchResults({
        resultIndex: globalIndex,
        resultCount: totalCount,
      })
    },
    [focusSessionPane],
  )

  const goToSplitSearchMatchReverse = useCallback(
    (
      sessionId: string,
      localIndex: number,
      query: string,
      globalIndex: number,
      totalCount: number,
    ) => {
      const api = terminalApisRef.current.get(sessionId)
      if (!api?.findLastMatch?.(query)) return

      const sessionCount =
        searchResultsBySessionRef.current.get(sessionId)?.resultCount ?? 0
      if (sessionCount <= 0) return

      const stepsBack = sessionCount - 1 - localIndex

      suppressSearchReportRef.current = true
      try {
        for (let step = 0; step < stepsBack; step++) {
          if (!api.findPreviousMatch?.(query)) break
        }
      } finally {
        suppressSearchReportRef.current = false
      }

      searchResultsBySessionRef.current.set(sessionId, {
        resultIndex: localIndex,
        resultCount: sessionCount,
      })

      suppressSearchFocusRerunRef.current = true
      focusSessionPane(sessionId)
      suppressSearchFocusRerunRef.current = false

      lastSearchSessionIdRef.current = sessionId
      lastGlobalSearchIndexRef.current = globalIndex
      setTerminalSearchResults({
        resultIndex: globalIndex,
        resultCount: totalCount,
      })
    },
    [focusSessionPane],
  )

  useEffect(() => {
    if (!terminalSearchOpen || suppressSearchFocusRerunRef.current) return
    if (!terminalSearchQuery.trim()) {
      setTerminalSearchResults(null)
      clearAllTerminalSearch()
      return
    }

    const targetIds = getSearchTargetSessionIds()
    const currentIds = searchSessionIdsRef.current
    const idsChanged =
      targetIds.length !== currentIds.length ||
      targetIds.some((id, index) => id !== currentIds[index])

    if (idsChanged) {
      searchResultsBySessionRef.current.clear()
      setSearchSessionIds(targetIds)
    }
  }, [
    clearAllTerminalSearch,
    focusedSessionId,
    getSearchTargetSessionIds,
    terminalSearchOpen,
    terminalSearchQuery,
  ])

  const toggleTerminalSearch = useCallback(() => {
    const nextOpen = !terminalSearchOpen
    if (nextOpen) {
      setOpen(true)
      const targetIds = getSearchTargetSessionIds()
      const focusTarget =
        targetIds.find((id) => id === focusedSessionIdRef.current) ??
        targetIds[0]
      if (focusTarget && focusedSessionIdRef.current !== focusTarget) {
        setFocusedSessionId(focusTarget)
      }
      if (targetIds.length > 0) {
        setSearchSessionIds(targetIds)
      }
    }
    setTerminalSearchOpen(nextOpen)
  }, [
    getSearchTargetSessionIds,
    setOpen,
    setTerminalSearchOpen,
    terminalSearchOpen,
  ])

  const searchTerminalOutput = useCallback(
    (
      query: string,
      options: { caseSensitive: boolean },
      sessionId?: string,
    ) => {
      lastSearchQueryRef.current = query
      if (!query.trim()) {
        clearTerminalSearchState()
        return
      }

      const targetIds = getSearchTargetSessionIds(sessionId)
      if (targetIds.length === 0) {
        setTerminalSearchResults({ resultIndex: -1, resultCount: 0 })
        return
      }

      const focusTarget =
        sessionId && targetIds.includes(sessionId)
          ? sessionId
          : targetIds.find((id) => id === focusedSessionIdRef.current) ??
            targetIds[0]

      lastSearchSessionIdRef.current = focusTarget
      searchResultsBySessionRef.current.clear()
      clearAllTerminalSearch()
      setSearchCaseSensitive(options.caseSensitive)
      setSearchSessionIds(targetIds)
      setTerminalSearchQuery(query)
    },
    [clearAllTerminalSearch, clearTerminalSearchState, getSearchTargetSessionIds],
  )

  const findNextTerminalMatch = useCallback(() => {
    const query = lastSearchQueryRef.current.trim()
    if (!query) return

    const splitPaneIds =
      searchSessionIdsRef.current.length > 1
        ? searchSessionIdsRef.current
        : null

    if (!splitPaneIds) {
      const sessionId =
        lastSearchSessionIdRef.current ?? resolveTerminalApiSessionId()
      if (!sessionId) return
      lastSearchSessionIdRef.current = sessionId
      terminalApisRef.current.get(sessionId)?.findNextMatch?.(query)
      return
    }

    const paneIds = splitPaneIds
    const aggregated = aggregateCliTerminalSearchResults(
      paneIds,
      searchResultsBySessionRef.current,
      lastSearchSessionIdRef.current,
    )
    const totalCount = aggregated.resultCount
    if (totalCount === 0) return

    const current =
      lastGlobalSearchIndexRef.current >= 0
        ? lastGlobalSearchIndexRef.current
        : aggregated.resultIndex >= 0
          ? aggregated.resultIndex
          : 0

    const nextGlobal = current + 1 >= totalCount ? 0 : current + 1
    const target = resolveGlobalMatchPosition(
      nextGlobal,
      paneIds,
      searchResultsBySessionRef.current,
    )
    if (!target) return

    goToSplitSearchMatch(
      target.sessionId,
      target.localIndex,
      query,
      nextGlobal,
      totalCount,
    )
  }, [
    goToSplitSearchMatch,
    resolveTerminalApiSessionId,
  ])

  const findPreviousTerminalMatch = useCallback(() => {
    const query = lastSearchQueryRef.current.trim()
    if (!query) return

    const splitPaneIds =
      searchSessionIdsRef.current.length > 1
        ? searchSessionIdsRef.current
        : null

    if (!splitPaneIds) {
      const sessionId =
        lastSearchSessionIdRef.current ?? resolveTerminalApiSessionId()
      if (!sessionId) return
      lastSearchSessionIdRef.current = sessionId
      terminalApisRef.current.get(sessionId)?.findPreviousMatch?.(query)
      return
    }

    const paneIds = splitPaneIds
    const aggregated = aggregateCliTerminalSearchResults(
      paneIds,
      searchResultsBySessionRef.current,
      lastSearchSessionIdRef.current,
    )
    const totalCount = aggregated.resultCount
    if (totalCount === 0) return

    const current =
      lastGlobalSearchIndexRef.current >= 0
        ? lastGlobalSearchIndexRef.current
        : aggregated.resultIndex >= 0
          ? aggregated.resultIndex
          : 0

    const prevGlobal =
      current - 1 < 0 ? totalCount - 1 : current - 1
    const target = resolveGlobalMatchPosition(
      prevGlobal,
      paneIds,
      searchResultsBySessionRef.current,
    )
    if (!target) return

    goToSplitSearchMatchReverse(
      target.sessionId,
      target.localIndex,
      query,
      prevGlobal,
      totalCount,
    )
  }, [
    goToSplitSearchMatchReverse,
    resolveTerminalApiSessionId,
  ])

  const copyTextToClipboard = useCallback(
    async (text: string, label: string) => {
      if (!text) {
        toast.error(`${t('Nothing to copy for')} ${t(label)}.`)
        return
      }
      try {
        await navigator.clipboard.writeText(text)
        toast.success(`${t('Copied')} ${t(label)}`)
      } catch {
        toast.error(`${t('Failed to copy')} ${t(label)}`)
      }
    },
    [t],
  )

  const copyTerminalSelection = useCallback(() => {
    const api = getTerminalApi()
    copyTextToClipboard(api?.getSelection?.() ?? '', 'selection')
  }, [copyTextToClipboard, getTerminalApi])

  const copyLastCommand = useCallback(() => {
    const api = getTerminalApi()
    copyTextToClipboard(api?.getLastCommand?.() ?? '', 'last command')
  }, [copyTextToClipboard, getTerminalApi])

  const copyTerminalOutput = useCallback(() => {
    const api = getTerminalApi()
    copyTextToClipboard(api?.getBufferText?.() ?? '', 'terminal output')
  }, [copyTextToClipboard, getTerminalApi])

  const exportTerminalOutput = useCallback(() => {
    const api = getTerminalApi()
    const content = api?.getBufferText?.() ?? ''
    if (!content.trim()) {
      toast.error(t('Nothing to export.'))
      return
    }
    const stamp = new Date().toISOString().replace(/[:.]/g, '-')
    downloadTextFile(`terminal-${projectId}-${stamp}.txt`, content)
    toast.success(t('Terminal output downloaded'))
  }, [getTerminalApi, projectId, t])

  const toggle = useCallback(() => {
    setOpen((prev) => {
      const next = !prev
      if (!next) {
        heightBeforeFullscreenRef.current = null
        setFullscreen(false)
      }
      return next
    })
  }, [setOpen])

  useKeyboardShortcut(
    'escape',
    () => {
      if (terminalSearchOpen) {
        setTerminalSearchOpen(false)
        return
      }
      exitFullscreen()
    },
    {
      enabled: open && (terminalSearchOpen || fullscreen),
      ignoreInputs: false,
      capture: true,
      stopPropagation: true,
    },
  )

  const getTerminalPrompt = useCallback(() => {
    const accountInfo =
      account && typeof account === 'object'
        ? {
            name: 'name' in account ? String(account.name ?? '') : undefined,
            email: 'email' in account ? String(account.email ?? '') : undefined,
          }
        : null

    return formatCliTerminalPrompt({
      username: resolveCliTerminalUsername(accountInfo),
      projectName: resolveCliTerminalProjectLabel(
        project ? { name: project.name } : null,
        projectId,
      ),
    })
  }, [account, project, projectId])

  const onToggleTerminalShortcut = useCallback(() => {
    toggle()
  }, [toggle])

  useKeyboardShortcut(CLI_SHELL_TOGGLE_SHORTCUT_COMBOS[0], onToggleTerminalShortcut, {
    enabled: true,
    ignoreInputs: false,
    capture: true,
  })
  useKeyboardShortcut(CLI_SHELL_TOGGLE_SHORTCUT_COMBOS[1], onToggleTerminalShortcut, {
    enabled: true,
    ignoreInputs: false,
    capture: true,
  })

  const onEnterFullscreenShortcut = useCallback((e: KeyboardEvent) => {
    if (!isFocusWithinCliShell()) return
    e.preventDefault()
    e.stopPropagation()
    enterFullscreen()
  }, [enterFullscreen])

  useKeyboardShortcut(
    CLI_SHELL_FULLSCREEN_SHORTCUT_COMBOS[0],
    onEnterFullscreenShortcut,
    {
      enabled: open && !fullscreen,
      ignoreInputs: false,
      capture: true,
      preventDefault: false,
      stopPropagation: false,
    },
  )
  useKeyboardShortcut(
    CLI_SHELL_FULLSCREEN_SHORTCUT_COMBOS[1],
    onEnterFullscreenShortcut,
    {
      enabled: open && !fullscreen,
      ignoreInputs: false,
      capture: true,
      preventDefault: false,
      stopPropagation: false,
    },
  )

  const onToggleTerminalSearchShortcut = useCallback(() => {
    toggleTerminalSearch()
  }, [toggleTerminalSearch])

  useKeyboardShortcut(CLI_SHELL_SEARCH_SHORTCUT_COMBOS[0], onToggleTerminalSearchShortcut, {
    enabled: open,
    ignoreInputs: false,
    capture: true,
  })
  useKeyboardShortcut(CLI_SHELL_SEARCH_SHORTCUT_COMBOS[1], onToggleTerminalSearchShortcut, {
    enabled: open,
    ignoreInputs: false,
    capture: true,
  })

  const onCreateTerminalShortcut = useCallback(() => {
    setOpen(true)
    createSession()
  }, [setOpen, createSession])

  useKeyboardShortcut(
    CLI_SHELL_NEW_TERMINAL_SHORTCUT_COMBOS[0],
    onCreateTerminalShortcut,
    {
      enabled: true,
      ignoreInputs: false,
      capture: true,
    },
  )
  useKeyboardShortcut(
    CLI_SHELL_NEW_TERMINAL_SHORTCUT_COMBOS[1],
    onCreateTerminalShortcut,
    {
      enabled: true,
      ignoreInputs: false,
      capture: true,
    },
  )

  const visiblePaneSessionIds = useMemo(
    () =>
      getCliShellVisiblePaneSessionIds(
        activeSessionId,
        sessions,
        splitPaneSessionIds,
      ),
    [activeSessionId, sessions, splitPaneSessionIds],
  )

  const value = useMemo<CliShellContextValue>(
    () => ({
      open,
      setOpen,
      panelEverOpened,
      toggle,
      status,
      sessions,
      activeSessionId,
      setActiveSessionId,
      selectSession,
      focusedSessionId,
      focusSessionPane,
      splitPaneSessionIds,
      visiblePaneSessionIds,
      splitSession,
      createSession,
      removeSession,
      renameSession,
      reorderRootSessions,
      registerTerminal,
      unregisterTerminal,
      writeSessionWelcome,
      runCommand,
      clearOutput,
      completeTab,
      cancelRunning,
      isRunning,
      runningSessionId,
      isSessionRunning,
      height,
      setHeight,
      fullscreen,
      toggleFullscreen,
      exitFullscreen,
      retryBootstrap,
      bootstrapError,
      getTerminalPrompt,
      getCommandHistory,
      persistCommandHistory,
      getSuggestionCommands,
      terminalSearchOpen,
      setTerminalSearchOpen,
      terminalSearchQuery,
      searchSessionIds,
      searchCaseSensitive,
      terminalSearchResults,
      reportSearchResults,
      toggleTerminalSearch,
      searchTerminalOutput,
      findNextTerminalMatch,
      findPreviousTerminalMatch,
      copyTerminalSelection,
      copyLastCommand,
      copyTerminalOutput,
      exportTerminalOutput,
    }),
    [
      open,
      setOpen,
      panelEverOpened,
      toggle,
      status,
      sessions,
      activeSessionId,
      setActiveSessionId,
      selectSession,
      focusedSessionId,
      focusSessionPane,
      splitPaneSessionIds,
      visiblePaneSessionIds,
      splitSession,
      createSession,
      removeSession,
      renameSession,
      reorderRootSessions,
      registerTerminal,
      unregisterTerminal,
      writeSessionWelcome,
      runCommand,
      clearOutput,
      completeTab,
      cancelRunning,
      isRunning,
      runningSessionId,
      isSessionRunning,
      height,
      setHeight,
      fullscreen,
      toggleFullscreen,
      exitFullscreen,
      retryBootstrap,
      bootstrapError,
      getTerminalPrompt,
      getCommandHistory,
      persistCommandHistory,
      getSuggestionCommands,
      terminalSearchOpen,
      setTerminalSearchOpen,
      terminalSearchQuery,
      searchSessionIds,
      searchCaseSensitive,
      terminalSearchResults,
      reportSearchResults,
      toggleTerminalSearch,
      searchTerminalOutput,
      findNextTerminalMatch,
      findPreviousTerminalMatch,
      copyTerminalSelection,
      copyLastCommand,
      copyTerminalOutput,
      exportTerminalOutput,
    ],
  )

  return (
    <CliShellContext.Provider value={value}>{children}</CliShellContext.Provider>
  )
}
