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
} from '@/lib/cli-shell/bootstrap-cli-container'
import {
  CLI_PROJECT_CWD,
  createCliShellWelcomeLines,
} from '@/lib/cli-shell/constants'
import { resolveConsoleCliAuth } from '@/lib/cli-shell/console-session'
import {
  installCliFetchBridge,
  removeCliFetchBridge,
} from '@/lib/cli-shell/fetch-bridge'
import { isAppwriteCliCommand } from '@/lib/cli-shell/is-appwrite-command'
import { prepareCliCommand } from '@/lib/cli-shell/prepare-command'
import {
  applyTabCompletion,
  tabComplete,
} from '@/lib/cli-shell/tab-completion'
import type {
  CliShellContainer,
  CliShellLine,
  CliShellStatus,
} from '@/lib/cli-shell/types'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import {
  useCliShellHeight,
  useCliShellOpen,
  type ConsoleAccountCache,
} from '@/lib/react-query/hooks/auth'
import { clampCliShellHeightPx } from '@/lib/user-prefs-keys'

type CliShellContextValue = {
  open: boolean
  setOpen: (open: boolean) => void
  toggle: () => void
  status: CliShellStatus
  lines: CliShellLine[]
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

type ProjectBootstrapState = {
  promise: Promise<CliShellContainer> | null
  container: CliShellContainer | null
}

const projectBootstrapState = new Map<string, ProjectBootstrapState>()

function getProjectBootstrapState(projectId: string): ProjectBootstrapState {
  const existing = projectBootstrapState.get(projectId)
  if (existing) return existing
  const created: ProjectBootstrapState = { promise: null, container: null }
  projectBootstrapState.set(projectId, created)
  return created
}

export function CliShellProvider({ projectId, children }: CliShellProviderProps) {
  const { account, isLoading: isAccountLoading } = useAuth()
  const consoleAccount = account as ConsoleAccountCache | undefined
  const { isOpen: open, setIsOpen: setOpen } = useCliShellOpen(consoleAccount)
  const { heightPx, setHeightPx } = useCliShellHeight(consoleAccount)
  const height = clampCliShellHeightPx(heightPx)

  const [status, setStatus] = useState<CliShellStatus>('idle')
  const [lines, setLines] = useState<CliShellLine[]>(() =>
    createCliShellWelcomeLines(),
  )
  const [isRunning, setIsRunning] = useState(false)
  const [bootstrapError, setBootstrapError] = useState<string | null>(null)

  const containerRef = useRef<CliShellContainer | null>(null)
  const bootstrapPromiseRef = useRef<Promise<CliShellContainer> | null>(null)
  const bootstrapLineIndexRef = useRef<number | null>(null)
  const bootstrapPendingRef = useRef<string | null>(null)
  const bootstrapTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const bootstrapLastUpdateRef = useRef(0)
  const bootstrapSilentRef = useRef(false)
  const authInitializedRef = useRef(false)

  const BOOTSTRAP_LINE_MIN_INTERVAL_MS = 250

  const appendLine = useCallback((line: CliShellLine) => {
    setLines((prev) => [...prev, line])
  }, [])

  const setBootstrapLine = useCallback((text: string) => {
    setLines((prev) => {
      const index = bootstrapLineIndexRef.current
      if (index === null || index >= prev.length || prev[index]?.type !== 'system') {
        bootstrapLineIndexRef.current = prev.length
        return [...prev, { type: 'system', text }]
      }
      const next = [...prev]
      next[index] = { type: 'system', text }
      return next
    })
  }, [])

  const scheduleBootstrapLine = useCallback(
    (text: string) => {
      bootstrapPendingRef.current = text

      const flush = () => {
        bootstrapTimerRef.current = null
        const pending = bootstrapPendingRef.current
        if (pending !== null) {
          setBootstrapLine(pending)
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
    [setBootstrapLine],
  )

  const finalizeBootstrapLine = useCallback(
    (text: string) => {
      if (bootstrapTimerRef.current !== null) {
        clearTimeout(bootstrapTimerRef.current)
        bootstrapTimerRef.current = null
      }
      bootstrapPendingRef.current = null
      setBootstrapLine(text)
      bootstrapLineIndexRef.current = null
    },
    [setBootstrapLine],
  )

  const resetBootstrapLine = useCallback(() => {
    if (bootstrapTimerRef.current !== null) {
      clearTimeout(bootstrapTimerRef.current)
      bootstrapTimerRef.current = null
    }
    bootstrapPendingRef.current = null
    bootstrapLineIndexRef.current = null
  }, [])

  const setHeight = useCallback(
    (next: number) => {
      setHeightPx(clampCliShellHeightPx(next))
    },
    [setHeightPx],
  )

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

      authInitializedRef.current = true
    },
    [account, isAccountLoading, projectId],
  )

  const ensureRuntime = useCallback(
    async (options?: { silent?: boolean }): Promise<CliShellContainer> => {
      const projectBootstrap = getProjectBootstrapState(projectId)

      if (containerRef.current) return containerRef.current
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

      const promise = bootstrapCliRuntime(
        {
          projectId,
          projectEndpoint,
          consoleEndpoint,
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
              appendLine({ type: 'stderr', text: message })
            }
          }

          setStatus('ready')
          if (!bootstrapSilentRef.current) {
            finalizeBootstrapLine('Appwrite CLI is ready.')
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
      appendLine,
      finalizeBootstrapLine,
      initializeCliAuth,
      projectId,
      resetBootstrapLine,
      scheduleBootstrapLine,
    ],
  )

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
    void ensureRuntime({ silent: !open }).catch(() => {})
  }, [ensureRuntime, open])

  useEffect(() => {
    containerRef.current = null
    bootstrapPromiseRef.current = null
    authInitializedRef.current = false
    projectBootstrapState.delete(projectId)
    setStatus('idle')
    setLines(createCliShellWelcomeLines())
    setBootstrapError(null)
    resetBootstrapLine()
    setIsRunning(false)
    removeCliFetchBridge()
  }, [projectId, resetBootstrapLine])

  useEffect(() => {
    return () => {
      removeCliFetchBridge()
    }
  }, [])

  const clearOutput = useCallback(() => {
    setLines([])
  }, [])

  const completeTab = useCallback(
    (input: string, cursor: number, listOnly = false) => {
      const outcome = tabComplete(input, cursor, {
        vfs: containerRef.current?.vfs ?? null,
      }, listOnly)

      if (outcome.kind === 'none') return null

      if (outcome.kind === 'list') {
        appendLine({
          type: 'system',
          text: outcome.matches.join('  '),
        })
        return { input, cursor }
      }

      return applyTabCompletion(input, cursor, outcome)
    },
    [appendLine],
  )

  const runCommand = useCallback(
    async (rawCommand: string) => {
      const trimmed = rawCommand.trim()
      if (!trimmed) {
        appendLine({ type: 'command', text: '' })
        return
      }

      if (trimmed === 'clear' || trimmed === 'cls') {
        setLines([])
        return
      }

      const command = prepareCliCommand(rawCommand)
      if (!command) return

      appendLine({ type: 'command', text: trimmed })

      let container: CliShellContainer
      try {
        container = await ensureRuntime()
      } catch (error: unknown) {
        const message =
          error instanceof Error ? error.message : 'CLI shell is not ready.'
        if (!bootstrapError) {
          appendLine({ type: 'stderr', text: message })
        }
        return
      }

      if (isAppwriteCliCommand(rawCommand) && !authInitializedRef.current) {
        appendLine({
          type: 'stderr',
          text:
            bootstrapError ??
            'Appwrite CLI session is not ready. Retry setup or refresh the page.',
        })
        return
      }

      setIsRunning(true)
      setStatus('running')

      try {
        let streamedStdout = false
        let streamedStderr = false

        // Do not pass `signal` to container.run: almostnode treats any signal as
        // long-running and waits forever for process.exit() instead of idle timeout.
        const result = await container.run(command, {
          cwd: CLI_PROJECT_CWD,
          onStdout: (chunk) => {
            if (chunk) {
              streamedStdout = true
              appendLine({ type: 'stdout', text: chunk })
            }
          },
          onStderr: (chunk) => {
            if (chunk) {
              streamedStderr = true
              appendLine({ type: 'stderr', text: chunk })
            }
          },
        })

        if (result.stdout && !streamedStdout) {
          appendLine({ type: 'stdout', text: result.stdout })
        }
        if (result.stderr && !streamedStderr) {
          appendLine({ type: 'stderr', text: result.stderr })
        }
        if (
          result.exitCode !== 0 &&
          !result.stdout &&
          !result.stderr
        ) {
          appendLine({
            type: 'stderr',
            text: `Command failed with exit code ${result.exitCode}.`,
          })
        } else if (result.exitCode !== 0) {
          appendLine({
            type: 'system',
            text: `Process exited with code ${result.exitCode}.`,
          })
        }
      } catch (error: unknown) {
        const message =
          error instanceof Error ? error.message : 'Command failed.'
        appendLine({ type: 'stderr', text: message })
      } finally {
        setIsRunning(false)
        setStatus(containerRef.current ? 'ready' : 'error')
      }
    },
    [
      appendLine,
      bootstrapError,
      ensureRuntime,
    ],
  )

  const cancelRunning = useCallback(() => {
    // almostnode only supports cancellation when a signal is passed to run(),
    // which prevents Node-based CLIs from exiting. No-op for now.
  }, [])

  const toggle = useCallback(() => {
    setOpen((prev) => !prev)
  }, [setOpen])

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
      lines,
      runCommand,
      clearOutput,
      completeTab,
      cancelRunning,
      isRunning,
      height,
      setHeight,
      retryBootstrap,
      bootstrapError,
    }),
    [
      open,
      setOpen,
      toggle,
      status,
      lines,
      runCommand,
      clearOutput,
      completeTab,
      cancelRunning,
      isRunning,
      height,
      setHeight,
      retryBootstrap,
      bootstrapError,
    ],
  )

  return (
    <CliShellContext.Provider value={value}>{children}</CliShellContext.Provider>
  )
}
