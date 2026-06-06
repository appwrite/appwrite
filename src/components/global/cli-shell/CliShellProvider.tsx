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
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  getBaseEndpoint,
  getProjectApiEndpoint,
} from '@/lib/appwrite/sdk'
import { bootstrapCliContainer } from '@/lib/cli-shell/bootstrap-cli-container'
import { getConsoleSessionCookie } from '@/lib/cli-shell/console-session'
import type {
  CliShellContainer,
  CliShellLine,
  CliShellStatus,
} from '@/lib/cli-shell/types'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'

const CLI_SHELL_HEIGHT_KEY = 'console.cliShellHeight'
const DEFAULT_SHELL_HEIGHT = 280
const MIN_SHELL_HEIGHT = 160
const MAX_SHELL_HEIGHT_RATIO = 0.55

type CliShellContextValue = {
  open: boolean
  setOpen: (open: boolean) => void
  toggle: () => void
  status: CliShellStatus
  lines: CliShellLine[]
  runCommand: (command: string) => Promise<void>
  clearOutput: () => void
  cancelRunning: () => void
  isRunning: boolean
  height: number
  setHeight: (height: number) => void
  retryBootstrap: () => void
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

function readStoredHeight(): number {
  if (typeof window === 'undefined') return DEFAULT_SHELL_HEIGHT
  try {
    const raw = window.localStorage.getItem(CLI_SHELL_HEIGHT_KEY)
    const parsed = raw ? Number.parseInt(raw, 10) : NaN
    if (Number.isFinite(parsed) && parsed >= MIN_SHELL_HEIGHT) {
      return parsed
    }
  } catch {
    /* private mode */
  }
  return DEFAULT_SHELL_HEIGHT
}

type CliShellProviderProps = {
  projectId: string
  children: ReactNode
}

export function CliShellProvider({ projectId, children }: CliShellProviderProps) {
  const { account } = useAuth()

  const [open, setOpen] = useState(false)
  const [status, setStatus] = useState<CliShellStatus>('idle')
  const [lines, setLines] = useState<CliShellLine[]>([])
  const [height, setHeightState] = useState(readStoredHeight)
  const [isRunning, setIsRunning] = useState(false)

  const containerRef = useRef<CliShellContainer | null>(null)
  const abortRef = useRef<AbortController | null>(null)
  const bootstrapPromiseRef = useRef<Promise<CliShellContainer> | null>(null)

  const appendLine = useCallback((line: CliShellLine) => {
    setLines((prev) => [...prev, line])
  }, [])

  const setHeight = useCallback((next: number) => {
    const maxHeight = Math.floor(window.innerHeight * MAX_SHELL_HEIGHT_RATIO)
    const clamped = Math.min(
      Math.max(next, MIN_SHELL_HEIGHT),
      Math.max(maxHeight, MIN_SHELL_HEIGHT),
    )
    setHeightState(clamped)
    try {
      window.localStorage.setItem(CLI_SHELL_HEIGHT_KEY, String(clamped))
    } catch {
      /* private mode */
    }
  }, [])

  const ensureContainer = useCallback(async (): Promise<CliShellContainer> => {
    if (containerRef.current) return containerRef.current
    if (bootstrapPromiseRef.current) return bootstrapPromiseRef.current

    const sessionCookie = getConsoleSessionCookie()
    if (!sessionCookie) {
      throw new Error(
        'No active console session. Sign in again to use the Appwrite CLI.',
      )
    }

    const email = account?.email?.trim()
    if (!email) {
      throw new Error('Account email is required to configure the CLI session.')
    }

    const projectEndpoint = getProjectApiEndpoint(projectId)
    const consoleEndpoint = getBaseEndpoint()

    setStatus('bootstrapping')
    appendLine({
      type: 'system',
      text: 'Installing Appwrite CLI in the browser runtime (first open may take a moment)...',
    })

    const promise = bootstrapCliContainer({
      projectId,
      projectEndpoint,
      consoleEndpoint,
      email,
      sessionCookie,
    })
      .then((container) => {
        containerRef.current = container
        setStatus('ready')
        appendLine({
          type: 'system',
          text: 'Appwrite CLI is ready. Type a command and press Enter.',
        })
        return container
      })
      .catch((error: unknown) => {
        setStatus('error')
        const message =
          error instanceof Error ? error.message : 'Failed to start CLI shell.'
        appendLine({ type: 'stderr', text: message })
        throw error
      })
      .finally(() => {
        bootstrapPromiseRef.current = null
      })

    bootstrapPromiseRef.current = promise
    return promise
  }, [account?.email, appendLine, projectId])

  const retryBootstrap = useCallback(() => {
    containerRef.current = null
    bootstrapPromiseRef.current = null
    setStatus('idle')
    if (open) {
      void ensureContainer().catch(() => {})
    }
  }, [ensureContainer, open])

  useEffect(() => {
    if (!open) return
    void ensureContainer().catch(() => {})
  }, [open, ensureContainer])

  useEffect(() => {
    containerRef.current = null
    bootstrapPromiseRef.current = null
    setStatus('idle')
    setLines([])
    setIsRunning(false)
    abortRef.current?.abort()
    abortRef.current = null
  }, [projectId])

  const runCommand = useCallback(
    async (rawCommand: string) => {
      const command = rawCommand.trim()
      if (!command) return

      appendLine({ type: 'command', text: command })

      let container: CliShellContainer
      try {
        container = await ensureContainer()
      } catch {
        return
      }

      abortRef.current?.abort()
      const controller = new AbortController()
      abortRef.current = controller

      setIsRunning(true)
      setStatus('running')

      try {
        let streamedStdout = false
        let streamedStderr = false

        const result = await container.run(command, {
          signal: controller.signal,
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

        if (result.stdout && !streamedStdout && !controller.signal.aborted) {
          appendLine({ type: 'stdout', text: result.stdout })
        }
        if (result.stderr && !streamedStderr && !controller.signal.aborted) {
          appendLine({ type: 'stderr', text: result.stderr })
        }
        if (result.exitCode !== 0 && !controller.signal.aborted) {
          appendLine({
            type: 'system',
            text: `Process exited with code ${result.exitCode}.`,
          })
        }
      } catch (error: unknown) {
        if (controller.signal.aborted) {
          appendLine({ type: 'system', text: 'Command cancelled.' })
        } else {
          const message =
            error instanceof Error ? error.message : 'Command failed.'
          appendLine({ type: 'stderr', text: message })
        }
      } finally {
        if (abortRef.current === controller) {
          abortRef.current = null
        }
        setIsRunning(false)
        setStatus(containerRef.current ? 'ready' : 'error')
      }
    },
    [appendLine, ensureContainer],
  )

  const cancelRunning = useCallback(() => {
    abortRef.current?.abort()
  }, [])

  const clearOutput = useCallback(() => {
    setLines([])
  }, [])

  const toggle = useCallback(() => {
    setOpen((prev) => !prev)
  }, [])

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
      cancelRunning,
      isRunning,
      height,
      setHeight,
      retryBootstrap,
    }),
    [
      open,
      toggle,
      status,
      lines,
      runCommand,
      clearOutput,
      cancelRunning,
      isRunning,
      height,
      setHeight,
      retryBootstrap,
    ],
  )

  return (
    <CliShellContext.Provider value={value}>{children}</CliShellContext.Provider>
  )
}
