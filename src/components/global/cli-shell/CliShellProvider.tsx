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
  createCliShellWelcomeLines,
} from '@/lib/cli-shell/constants'
import { resolveConsoleCliAuth } from '@/lib/cli-shell/console-session'
import {
  installCliFetchBridge,
  removeCliFetchBridge,
} from '@/lib/cli-shell/fetch-bridge'
import { getBlockedCliCommandMessage } from '@/lib/cli-shell/blocked-cli-commands'
import { isAppwriteCliCommand } from '@/lib/cli-shell/is-appwrite-command'
import { ensureAppwriteBinStub } from '@/lib/cli-shell/install-appwrite-cli'
import { prepareCliCommand } from '@/lib/cli-shell/prepare-command'
import {
  CLI_TERMINAL_MUTED,
  CLI_TERMINAL_RESET,
  createTerminalOutputLinkifier,
  type CliTerminalApi,
  writeCliShellLine,
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
import {
  useCliShellHeight,
  useCliShellOpen,
  type ConsoleAccountCache,
} from '@/lib/react-query/hooks/auth'
import { useProject } from '@/lib/react-query/hooks'
import { clampCliShellHeightPx } from '@/lib/user-prefs-keys'

type CliShellContextValue = {
  open: boolean
  setOpen: (open: boolean) => void
  toggle: () => void
  status: CliShellStatus
  registerTerminal: (api: CliTerminalApi | null) => void
  runCommand: (command: string) => Promise<void>
  clearOutput: () => void
  completeTab: (
    input: string,
    cursor: number,
    listOnly?: boolean,
  ) => { input: string; cursor: number } | null
  cancelRunning: () => void
  isRunning: boolean
  height: number
  setHeight: (height: number) => void
  fullscreen: boolean
  toggleFullscreen: () => void
  exitFullscreen: () => void
  retryBootstrap: () => void
  bootstrapError: string | null
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

type CliShellProviderProps = {
  projectId: string
  children: ReactNode
}

export function CliShellProvider({ projectId, children }: CliShellProviderProps) {
  const { account, isLoading: isAccountLoading } = useAuth()
  const { project } = useProject(projectId)
  const organizationId = project?.teamId
  const consoleAccount = account as ConsoleAccountCache | undefined
  const { isOpen: open, setIsOpen: setOpen } = useCliShellOpen(consoleAccount)
  const { heightPx, setHeightPx } = useCliShellHeight(consoleAccount)
  const height = clampCliShellHeightPx(heightPx)

  const [status, setStatus] = useState<CliShellStatus>('idle')
  const [isRunning, setIsRunning] = useState(false)
  const [bootstrapError, setBootstrapError] = useState<string | null>(null)
  const isRunningRef = useRef(false)
  isRunningRef.current = isRunning

  const containerRef = useRef<CliShellContainer | null>(null)
  const terminalApiRef = useRef<CliTerminalApi | null>(null)
  const terminalWelcomeWrittenRef = useRef(false)
  const pendingBootstrapMessagesRef = useRef<string[]>([])
  const bootstrapPromiseRef = useRef<Promise<CliShellContainer> | null>(null)
  const bootstrapPendingRef = useRef<string | null>(null)
  const bootstrapTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const bootstrapLastUpdateRef = useRef(0)
  const bootstrapSilentRef = useRef(false)
  const bootstrapReadyAnnouncedRef = useRef(false)
  const bootstrapLastWrittenRef = useRef<string | null>(null)
  const authInitializedRef = useRef(false)
  const heightBeforeFullscreenRef = useRef<number | null>(null)

  const [fullscreen, setFullscreen] = useState(false)

  const BOOTSTRAP_LINE_MIN_INTERVAL_MS = 250

  const showInputPromptIfIdle = useCallback(() => {
    if (!isRunningRef.current) {
      terminalApiRef.current?.showInputPrompt?.()
    }
  }, [])

  const writeWelcome = useCallback((api: CliTerminalApi) => {
    for (const line of createCliShellWelcomeLines()) {
      writeCliShellLine(api, line)
    }
  }, [])

  const registerTerminal = useCallback(
    (api: CliTerminalApi | null) => {
      terminalApiRef.current = api
      if (!api) {
        terminalWelcomeWrittenRef.current = false
        return
      }

      if (!terminalWelcomeWrittenRef.current) {
        terminalWelcomeWrittenRef.current = true
        writeWelcome(api)
      }

      for (const message of pendingBootstrapMessagesRef.current) {
        if (
          message === CLI_BOOTSTRAP_READY_MESSAGE &&
          bootstrapReadyAnnouncedRef.current
        ) {
          continue
        }
        if (message === CLI_BOOTSTRAP_READY_MESSAGE) {
          bootstrapReadyAnnouncedRef.current = true
        }
        api.writeln(`${CLI_TERMINAL_MUTED}${message}${CLI_TERMINAL_RESET}`)
        bootstrapLastWrittenRef.current = message
      }
      pendingBootstrapMessagesRef.current = []
      showInputPromptIfIdle()
    },
    [showInputPromptIfIdle, writeWelcome],
  )

  const writeBootstrapMessage = useCallback((text: string) => {
    if (
      text === CLI_BOOTSTRAP_READY_MESSAGE &&
      bootstrapReadyAnnouncedRef.current
    ) {
      return
    }
    if (bootstrapLastWrittenRef.current === text) {
      return
    }

    const api = terminalApiRef.current
    if (api) {
      if (text === CLI_BOOTSTRAP_READY_MESSAGE) {
        bootstrapReadyAnnouncedRef.current = true
      }
      api.writeln(`${CLI_TERMINAL_MUTED}${text}${CLI_TERMINAL_RESET}`)
      bootstrapLastWrittenRef.current = text
      showInputPromptIfIdle()
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
  }, [showInputPromptIfIdle])

  const scheduleBootstrapLine = useCallback(
    (text: string) => {
      bootstrapPendingRef.current = text

      const flush = () => {
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
    if (bootstrapTimerRef.current !== null) {
      clearTimeout(bootstrapTimerRef.current)
      bootstrapTimerRef.current = null
    }
    bootstrapPendingRef.current = null
  }, [])

  const writeStderrLine = useCallback(
    (text: string, options?: { showPromptAfter?: boolean }) => {
      writeCliShellLine(
        terminalApiRef.current,
        { type: 'stderr', text },
        options,
      )
    },
    [],
  )

  const writeSystemLine = useCallback((text: string) => {
    writeCliShellLine(
      terminalApiRef.current,
      { type: 'system', text },
      { showPromptAfter: !isRunningRef.current },
    )
  }, [])

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
    if (!open && fullscreen) {
      heightBeforeFullscreenRef.current = null
      setFullscreen(false)
    }
  }, [fullscreen, open])

  const initializeCliAuth = useCallback(
    async (container: CliShellContainer): Promise<void> => {
      if (authInitializedRef.current || isAccountLoading) return

      const accountUser = account as Models.User | undefined
      const email = accountUser?.email?.trim()
      if (!email) return

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
    },
    [account, isAccountLoading, organizationId, projectId],
  )

  const ensureRuntime = useCallback(
    async (options?: { silent?: boolean }): Promise<CliShellContainer> => {
      const projectBootstrap = getProjectBootstrapState(projectId)

      if (containerRef.current) {
        ensureAppwriteBinStub(containerRef.current.vfs)
        return containerRef.current
      }
      if (projectBootstrap.container) {
        ensureAppwriteBinStub(projectBootstrap.container.vfs)
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
          containerRef.current = container
          projectBootstrap.container = container
          projectBootstrap.promise = null

          try {
            await initializeCliAuth(container)
            setBootstrapError(null)
          } catch (error: unknown) {
            const message =
              error instanceof Error
                ? error.message
                : 'Failed to configure Appwrite CLI session.'
            setBootstrapError(message)
            if (!bootstrapSilentRef.current) {
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
  }, [account, initializeCliAuth, isAccountLoading])

  const retryBootstrap = useCallback(() => {
    containerRef.current = null
    bootstrapPromiseRef.current = null
    bootstrapReadyAnnouncedRef.current = false
    authInitializedRef.current = false
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
    void ensureRuntime({ silent: !open }).catch(() => {})
  }, [ensureRuntime, open])

  useEffect(() => {
    containerRef.current = null
    bootstrapPromiseRef.current = null
    bootstrapReadyAnnouncedRef.current = false
    bootstrapLastWrittenRef.current = null
    authInitializedRef.current = false
    deleteProjectBootstrapState(projectId)
    setStatus('idle')
    setBootstrapError(null)
    resetBootstrapLine()
    setIsRunning(false)
    terminalWelcomeWrittenRef.current = false
    pendingBootstrapMessagesRef.current = []
    terminalApiRef.current?.clear()
    removeCliFetchBridge()
  }, [projectId, resetBootstrapLine])

  useEffect(() => {
    return () => {
      removeCliFetchBridge()
    }
  }, [])

  const clearOutput = useCallback(() => {
    const api = terminalApiRef.current
    if (api?.clearScreen) {
      api.clearScreen()
      return
    }
    api?.clear()
    showInputPromptIfIdle()
  }, [showInputPromptIfIdle])

  const completeTab = useCallback(
    (input: string, cursor: number, listOnly = false) => {
      const outcome = tabComplete(input, cursor, {
        vfs: containerRef.current?.vfs ?? null,
      }, listOnly)

      if (outcome.kind === 'none') return null

      if (outcome.kind === 'list') {
        writeSystemLine(outcome.matches.join('  '))
        return { input, cursor }
      }

      return applyTabCompletion(input, cursor, outcome)
    },
    [writeSystemLine],
  )

  const runCommand = useCallback(
    async (rawCommand: string) => {
      const trimmed = rawCommand.trim()
      if (!trimmed) return

      const blockedMessage = getBlockedCliCommandMessage(rawCommand)
      if (blockedMessage) {
        const lines = blockedMessage.split('\n')
        lines.forEach((line, index) => {
          writeStderrLine(line, {
            showPromptAfter: index === lines.length - 1,
          })
        })
        return
      }

      const command = prepareCliCommand(rawCommand)
      if (!command) return

      let container: CliShellContainer
      try {
        container = await ensureRuntime()
      } catch (error: unknown) {
        const message =
          error instanceof Error ? error.message : 'CLI shell is not ready.'
        if (!bootstrapError) {
          writeStderrLine(message, { showPromptAfter: true })
        }
        return
      }

      if (isAppwriteCliCommand(rawCommand) && !authInitializedRef.current) {
        writeStderrLine(
          bootstrapError ??
            'Appwrite CLI session is not ready. Retry setup or refresh the page.',
          { showPromptAfter: true },
        )
        return
      }

      setIsRunning(true)
      setStatus('running')

      let streamedStdout = false
      let streamedStderr = false
      const stdoutLinkifier = createTerminalOutputLinkifier()
      const stderrLinkifier = createTerminalOutputLinkifier()

      // Do not pass `signal`: almostnode treats any signal as long-running and
      // waits forever for process.exit() instead of using the idle timeout.
      const runOptions: CliShellRunOptions = {
        cwd: CLI_PROJECT_CWD,
        onStdout: (chunk) => {
          if (!chunk) return
          streamedStdout = true
          stdoutLinkifier.write(terminalApiRef.current, chunk)
        },
        onStderr: (chunk) => {
          if (!chunk) return
          streamedStderr = true
          stderrLinkifier.write(terminalApiRef.current, chunk)
        },
      }

      try {
        const result = await container.run(command, runOptions)

        if (result.stdout && !streamedStdout) {
          writeCliTerminalRaw(terminalApiRef.current, result.stdout)
        }
        if (result.stderr && !streamedStderr) {
          writeCliTerminalRaw(terminalApiRef.current, result.stderr)
        }
        if (
          result.exitCode !== 0 &&
          !result.stdout &&
          !result.stderr
        ) {
          writeStderrLine(`Command failed with exit code ${result.exitCode}.`)
        } else if (result.exitCode !== 0) {
          writeSystemLine(`Process exited with code ${result.exitCode}.`)
        }
      } catch (error: unknown) {
        const message =
          error instanceof Error ? error.message : 'Command failed.'
        writeStderrLine(message)
      } finally {
        stdoutLinkifier.flush(terminalApiRef.current)
        stderrLinkifier.flush(terminalApiRef.current)
        setIsRunning(false)
        setStatus(containerRef.current ? 'ready' : 'error')
      }
    },
    [
      bootstrapError,
      clearOutput,
      ensureRuntime,
      writeStderrLine,
      writeSystemLine,
    ],
  )

  const cancelRunning = useCallback(() => {
    /* Command cancellation is not supported in the browser shell. */
  }, [])

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
      exitFullscreen()
    },
    { enabled: fullscreen, ignoreInputs: false },
  )

  useKeyboardShortcut(
    'control+`',
    () => {
      toggle()
    },
    { enabled: true, ignoreInputs: false },
  )
  useKeyboardShortcut(
    'meta+`',
    () => {
      toggle()
    },
    { enabled: true, ignoreInputs: false },
  )

  const value = useMemo<CliShellContextValue>(
    () => ({
      open,
      setOpen,
      toggle,
      status,
      registerTerminal,
      runCommand,
      clearOutput,
      completeTab,
      cancelRunning,
      isRunning,
      height,
      setHeight,
      fullscreen,
      toggleFullscreen,
      exitFullscreen,
      retryBootstrap,
      bootstrapError,
    }),
    [
      open,
      setOpen,
      toggle,
      status,
      registerTerminal,
      runCommand,
      clearOutput,
      completeTab,
      cancelRunning,
      isRunning,
      height,
      setHeight,
      fullscreen,
      toggleFullscreen,
      exitFullscreen,
      retryBootstrap,
      bootstrapError,
    ],
  )

  return (
    <CliShellContext.Provider value={value}>{children}</CliShellContext.Provider>
  )
}
