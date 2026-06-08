import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type MouseEvent as ReactMouseEvent,
} from 'react'
import { Terminal } from '@xterm/xterm'
import { FitAddon } from '@xterm/addon-fit'
import { createCliTerminalWebLinksAddon } from '@/lib/cli-shell/cli-terminal-web-links'
import { createCliTerminalSuggestionLinksAddon } from '@/lib/cli-shell/cli-terminal-suggestion-links'
import { CLI_SHELL_TRY_COMMANDS } from '@/lib/cli-shell/constants'
import '@xterm/xterm/css/xterm.css'
import { useTheme } from 'next-themes'
import {
  Loader2,
  Maximize2,
  Minimize2,
  RotateCcw,
  Terminal as TerminalIcon,
  Trash2,
  X,
  ChevronDown,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { CLI_SHELL_COLLAPSED_HEIGHT_PX } from '@/lib/cli-shell/constants'
import { createCliTerminalInputHandler } from '@/lib/cli-shell/cli-terminal-input'
import { getCliTerminalTheme } from '@/lib/cli-shell/cli-terminal-theme'
import type { CliTerminalApi } from '@/lib/cli-shell/cli-terminal-api'
import { useCliShell } from './CliShellProvider'

const CLI_SHELL_COLLAPSE_MS = 200

export function ProjectCliShell() {
  const { open, fullscreen, height } = useCliShell()
  const [hasOpenedPanel, setHasOpenedPanel] = useState(open)
  const [isCollapsing, setIsCollapsing] = useState(false)
  const [isResizing, setIsResizing] = useState(false)

  useEffect(() => {
    if (open) {
      setHasOpenedPanel(true)
      setIsCollapsing(false)
      return
    }

    if (!hasOpenedPanel) return

    setIsCollapsing(true)
    const timer = window.setTimeout(
      () => setIsCollapsing(false),
      CLI_SHELL_COLLAPSE_MS,
    )
    return () => window.clearTimeout(timer)
  }, [open, hasOpenedPanel])

  const showPanel = open || isCollapsing
  const showCollapsedBar = !open && !isCollapsing
  const isFullscreenOpen = open && fullscreen
  const containerHeight =
    open && !fullscreen ? height : CLI_SHELL_COLLAPSED_HEIGHT_PX

  return (
    <div
      className={cn(
        'bg-background',
        isFullscreenOpen
          ? 'fixed inset-0 z-[140] flex min-h-0 flex-col'
          : cn(
              'relative shrink-0 overflow-hidden border-t border-border',
              !isResizing && 'transition-[height] ease-out',
            ),
      )}
      style={
        isFullscreenOpen
          ? undefined
          : {
              height: containerHeight,
              transitionDuration: isResizing ? '0ms' : `${CLI_SHELL_COLLAPSE_MS}ms`,
            }
      }
    >
      {hasOpenedPanel ? (
        <div
          className={cn(
            'flex min-h-0 flex-col',
            isFullscreenOpen ? 'h-full flex-1' : 'h-full',
            !showPanel && 'hidden',
          )}
        >
          <ProjectCliShellPanel onResizingChange={setIsResizing} />
        </div>
      ) : null}
      {showCollapsedBar ? <ProjectCliShellCollapsedBar /> : null}
    </div>
  )
}

function CliShellHeaderTitle({
  showBootstrapSpinner = false,
}: {
  showBootstrapSpinner?: boolean
}) {
  return (
    <div className="flex min-w-0 items-center gap-2">
      <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/50 text-muted-foreground">
        <TerminalIcon className="h-3.5 w-3.5" />
      </span>
      <span className="truncate text-[13px] font-semibold text-foreground">
        Terminal
      </span>
      {showBootstrapSpinner ? (
        <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin text-muted-foreground" />
      ) : null}
    </div>
  )
}

function ProjectCliShellCollapsedBar() {
  const { setOpen, status } = useCliShell()
  const isBootstrapping = status === 'bootstrapping'

  return (
    <button
      type="button"
      onClick={() => setOpen(true)}
      className="flex h-full w-full cursor-pointer items-center bg-card/50 px-4 py-2.5 text-left transition-colors hover:bg-muted/40 sm:px-6"
      aria-label="Open terminal"
    >
      <CliShellHeaderTitle showBootstrapSpinner={isBootstrapping} />
    </button>
  )
}

type ProjectCliShellPanelProps = {
  onResizingChange?: (isResizing: boolean) => void
}

function ProjectCliShellPanel({ onResizingChange }: ProjectCliShellPanelProps) {
  const {
    open,
    setOpen,
    toggle,
    status,
    registerTerminal,
    unregisterTerminal,
    runCommand,
    clearOutput,
    completeTab,
    isRunning,
    height,
    setHeight,
    retryBootstrap,
    bootstrapError,
    fullscreen,
    toggleFullscreen,
    exitFullscreen,
    getTerminalPrompt,
  } = useCliShell()

  const { resolvedTheme } = useTheme()
  const [isResizing, setIsResizing] = useState(false)

  const terminalContainerRef = useRef<HTMLDivElement>(null)
  const terminalRef = useRef<Terminal | null>(null)
  const fitAddonRef = useRef<FitAddon | null>(null)
  const isRunningRef = useRef(isRunning)
  const wasRunningRef = useRef(false)
  const showInputPromptRef = useRef<(() => void) | null>(null)
  const inputSessionRef = useRef<ReturnType<
    typeof createCliTerminalInputHandler
  > | null>(null)
  const isResizingPanelRef = useRef(false)
  const resizeSessionRef = useRef<{
    startY: number
    startHeight: number
  } | null>(null)
  const fitRafRef = useRef<number | null>(null)
  const runCommandRef = useRef(runCommand)
  const completeTabRef = useRef(completeTab)
  const registerTerminalRef = useRef(registerTerminal)
  const unregisterTerminalRef = useRef(unregisterTerminal)
  const fullscreenRef = useRef(fullscreen)
  const exitFullscreenRef = useRef(exitFullscreen)
  const getTerminalPromptRef = useRef(getTerminalPrompt)

  isRunningRef.current = isRunning
  fullscreenRef.current = fullscreen
  exitFullscreenRef.current = exitFullscreen
  getTerminalPromptRef.current = getTerminalPrompt
  isResizingPanelRef.current = isResizing
  runCommandRef.current = runCommand
  completeTabRef.current = completeTab
  registerTerminalRef.current = registerTerminal
  unregisterTerminalRef.current = unregisterTerminal

  const fitTerminal = useCallback(() => {
    if (fitRafRef.current !== null) {
      cancelAnimationFrame(fitRafRef.current)
    }

    fitRafRef.current = requestAnimationFrame(() => {
      fitRafRef.current = null

      try {
        const fitAddon = fitAddonRef.current
        const terminal = terminalRef.current
        if (!fitAddon || !terminal) return

        const proposed = fitAddon.proposeDimensions()
        if (!proposed || proposed.cols <= 0 || proposed.rows <= 0) return
        if (
          proposed.cols === terminal.cols &&
          proposed.rows === terminal.rows
        ) {
          return
        }

        fitAddon.fit()
      } catch {
        /* container may have zero size during layout */
      }
    })
  }, [])

  useEffect(() => {
    const container = terminalContainerRef.current
    if (!container) return

    const terminal = new Terminal({
      cursorBlink: true,
      fontSize: 13,
      lineHeight: 1.4,
      fontFamily:
        'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
      theme: getCliTerminalTheme(resolvedTheme),
      scrollback: 5000,
      convertEol: true,
      allowTransparency: true,
      scrollSensitivity: 1,
    })

    const fitAddon = new FitAddon()
    const openLink = (event: MouseEvent, uri: string) => {
      window.open(uri, '_blank', 'noopener,noreferrer')
      event.preventDefault()
    }
    const webLinksAddon = createCliTerminalWebLinksAddon(openLink)
    const suggestionLinksAddon = createCliTerminalSuggestionLinksAddon(
      CLI_SHELL_TRY_COMMANDS,
      (event, command) => {
        event.preventDefault()
        inputSessionRef.current?.setInput(command)
      },
    )

    terminal.loadAddon(fitAddon)
    terminal.loadAddon(webLinksAddon)
    terminal.loadAddon(suggestionLinksAddon)
    terminal.attachCustomKeyEventHandler((event) => {
      if (event.type !== 'keydown' || event.key !== 'Escape') return true
      if (!fullscreenRef.current) return true
      event.preventDefault()
      exitFullscreenRef.current()
      return false
    })
    terminal.open(container)
    fitAddon.fit()

    terminalRef.current = terminal
    fitAddonRef.current = fitAddon

    const inputSession = createCliTerminalInputHandler({
      terminal,
      getIsRunning: () => isRunningRef.current,
      getPrompt: () => getTerminalPromptRef.current(),
      onRunCommand: (command) => runCommandRef.current(command),
      onTabComplete: (...args) => completeTabRef.current(...args),
    })
    inputSessionRef.current = inputSession

    const api: CliTerminalApi = {
      write: (data, callback) => terminal.write(data, callback),
      writeln: (data, callback) => terminal.writeln(data, callback),
      clear: () => terminal.clear(),
      focus: () => terminal.focus(),
      showInputPrompt: inputSession.showPrompt,
      clearScreen: inputSession.clearScreen,
      getPrompt: () => getTerminalPromptRef.current(),
      afterOutputLine: () => {
        webLinksAddon.scanLastWrittenLine()
        suggestionLinksAddon.scanLastWrittenLine()
      },
    }

    showInputPromptRef.current = inputSession.showPrompt

    registerTerminalRef.current(api)

    const dataDisposable = terminal.onData(inputSession.onData)

    terminal.focus()

    const resizeObserver = new ResizeObserver(() => {
      if (!isResizingPanelRef.current) {
        fitTerminal()
      }
    })
    resizeObserver.observe(container)

    return () => {
      if (fitRafRef.current !== null) {
        cancelAnimationFrame(fitRafRef.current)
        fitRafRef.current = null
      }
      dataDisposable.dispose()
      resizeObserver.disconnect()
      unregisterTerminalRef.current()
      terminal.dispose()
      showInputPromptRef.current = null
      inputSessionRef.current = null
      terminalRef.current = null
      fitAddonRef.current = null
    }
  }, [fitTerminal])

  useEffect(() => {
    const terminal = terminalRef.current
    if (!terminal) return
    terminal.options.theme = getCliTerminalTheme(resolvedTheme)
  }, [resolvedTheme])

  useEffect(() => {
    if (wasRunningRef.current && !isRunning) {
      showInputPromptRef.current?.()
    }
    wasRunningRef.current = isRunning
  }, [isRunning])

  useEffect(() => {
    if (open) fitTerminal()
  }, [fitTerminal, fullscreen, open])

  useEffect(() => {
    if (!isResizing) {
      fitTerminal()
    }
  }, [fitTerminal, isResizing])

  const handleResizeMouseDown = useCallback(
    (event: ReactMouseEvent) => {
      event.preventDefault()
      resizeSessionRef.current = {
        startY: event.clientY,
        startHeight: height,
      }
      setIsResizing(true)
    },
    [height],
  )

  useEffect(() => {
    onResizingChange?.(isResizing)
  }, [isResizing, onResizingChange])

  useEffect(() => {
    if (!isResizing || fullscreen) return

    const handleMouseMove = (event: MouseEvent) => {
      const session = resizeSessionRef.current
      if (!session) return
      setHeight(session.startHeight + (session.startY - event.clientY))
    }

    const handleMouseUp = () => {
      resizeSessionRef.current = null
      setIsResizing(false)
    }

    document.addEventListener('mousemove', handleMouseMove)
    document.addEventListener('mouseup', handleMouseUp)
    document.body.style.cursor = 'row-resize'
    document.body.style.userSelect = 'none'

    return () => {
      document.removeEventListener('mousemove', handleMouseMove)
      document.removeEventListener('mouseup', handleMouseUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }
  }, [fullscreen, isResizing, setHeight])

  const isBootstrapping = status === 'bootstrapping'

  const [headerActionsVisible, setHeaderActionsVisible] = useState(false)

  useEffect(() => {
    if (!open) {
      setHeaderActionsVisible(false)
      return
    }

    const raf = requestAnimationFrame(() => {
      setHeaderActionsVisible(true)
    })
    return () => cancelAnimationFrame(raf)
  }, [open])

  return (
    <div
      className={cn(
        'relative flex min-h-0 flex-col overflow-hidden bg-background',
        fullscreen ? 'h-full min-h-0 flex-1' : 'h-full',
      )}
    >
      {!fullscreen && (
        <div
          role="separator"
          aria-orientation="horizontal"
          aria-label="Resize terminal"
          onMouseDown={handleResizeMouseDown}
          className={cn(
            'absolute inset-x-0 top-0 z-10 flex h-1.5 w-full cursor-row-resize items-center justify-center transition-colors hover:bg-primary/20',
            isResizing && 'bg-primary/30',
          )}
        />
      )}

      <div className="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-card/50 px-4 py-2.5 sm:px-6">
        <CliShellHeaderTitle showBootstrapSpinner={isBootstrapping} />

        <div
          className={cn(
            'flex shrink-0 items-center gap-0.5 transition-opacity ease-out',
            headerActionsVisible
              ? 'opacity-100'
              : 'pointer-events-none opacity-0',
          )}
          style={{ transitionDuration: `${CLI_SHELL_COLLAPSE_MS}ms` }}
        >
          {(status === 'error' || bootstrapError) && (
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-8 w-8 text-muted-foreground"
              onClick={retryBootstrap}
              title="Retry setup"
            >
              <RotateCcw className="h-3.5 w-3.5" />
            </Button>
          )}
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-8 w-8 text-muted-foreground"
            onClick={clearOutput}
            title="Clear output"
          >
            <Trash2 className="h-3.5 w-3.5" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-8 w-8 text-muted-foreground"
            onClick={toggleFullscreen}
            title={fullscreen ? 'Exit full screen' : 'Full screen'}
          >
            {fullscreen ? (
              <Minimize2 className="h-3.5 w-3.5" />
            ) : (
              <Maximize2 className="h-3.5 w-3.5" />
            )}
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-8 w-8 text-muted-foreground"
            onClick={toggle}
            title="Minimize shell"
          >
            <ChevronDown className="h-3.5 w-3.5" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-8 w-8 text-muted-foreground"
            onClick={() => setOpen(false)}
            title="Close shell"
          >
            <X className="h-3.5 w-3.5" />
          </Button>
        </div>
      </div>

      <div className="min-h-0 flex-1 overflow-hidden bg-background px-4 pb-4 pt-3 sm:px-6 sm:pb-4">
        <div
          ref={terminalContainerRef}
          className={cn(
            'cli-terminal h-full min-h-0',
            '[&_.xterm]:h-full [&_.xterm-viewport]:!overflow-y-auto',
          )}
          onPointerDown={() => terminalRef.current?.focus()}
        />
      </div>
    </div>
  )
}
