import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type MouseEvent as ReactMouseEvent,
} from 'react'
import { createPortal } from 'react-dom'
import { Terminal } from '@xterm/xterm'
import { FitAddon } from '@xterm/addon-fit'
import { createCliTerminalWebLinksAddon } from '@/lib/cli-shell/cli-terminal-web-links'
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

export function ProjectCliShell() {
  const { open, fullscreen } = useCliShell()

  if (fullscreen && open && typeof document !== 'undefined') {
    return createPortal(
      <div className="fixed inset-0 z-[140] flex min-h-0 flex-col bg-background">
        <ProjectCliShellPanel />
      </div>,
      document.body,
    )
  }

  return (
    <div
      className="shrink-0 border-t border-border bg-background"
      style={open ? undefined : { height: CLI_SHELL_COLLAPSED_HEIGHT_PX }}
    >
      {!open ? <ProjectCliShellCollapsedBar /> : <ProjectCliShellPanel />}
    </div>
  )
}

function ProjectCliShellCollapsedBar() {
  const { setOpen } = useCliShell()

  return (
    <button
      type="button"
      onClick={() => setOpen(true)}
      className="flex h-full w-full cursor-pointer items-center gap-2 px-4 text-left text-[13px] font-medium text-muted-foreground transition-colors hover:bg-muted/40 hover:text-foreground sm:px-6"
      aria-label="Open terminal"
    >
      <TerminalIcon className="h-3.5 w-3.5 shrink-0 opacity-60" />
      <span>Terminal</span>
    </button>
  )
}

function ProjectCliShellPanel() {
  const {
    setOpen,
    toggle,
    status,
    registerTerminal,
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
  } = useCliShell()

  const { resolvedTheme } = useTheme()
  const [isResizing, setIsResizing] = useState(false)

  const panelRef = useRef<HTMLDivElement>(null)
  const terminalContainerRef = useRef<HTMLDivElement>(null)
  const terminalRef = useRef<Terminal | null>(null)
  const fitAddonRef = useRef<FitAddon | null>(null)
  const isRunningRef = useRef(isRunning)
  const wasRunningRef = useRef(false)
  const showInputPromptRef = useRef<(() => void) | null>(null)

  isRunningRef.current = isRunning

  const fitTerminal = useCallback(() => {
    requestAnimationFrame(() => {
      try {
        fitAddonRef.current?.fit()
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
      lineHeight: 1.5,
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

    terminal.loadAddon(fitAddon)
    terminal.loadAddon(webLinksAddon)
    terminal.open(container)
    fitAddon.fit()

    terminalRef.current = terminal
    fitAddonRef.current = fitAddon

    const inputSession = createCliTerminalInputHandler({
      terminal,
      getIsRunning: () => isRunningRef.current,
      onRunCommand: (command) => runCommand(command),
      onTabComplete: completeTab,
    })

    const api: CliTerminalApi = {
      write: (data, callback) => terminal.write(data, callback),
      writeln: (data, callback) => terminal.writeln(data, callback),
      clear: () => terminal.clear(),
      focus: () => terminal.focus(),
      showInputPrompt: inputSession.showPrompt,
      clearScreen: inputSession.clearScreen,
      afterOutputLine: () => webLinksAddon.scanLastWrittenLine(),
    }

    showInputPromptRef.current = inputSession.showPrompt

    registerTerminal(api)

    const dataDisposable = terminal.onData(inputSession.onData)

    terminal.focus()

    const resizeObserver = new ResizeObserver(() => {
      fitTerminal()
    })
    resizeObserver.observe(container)

    return () => {
      dataDisposable.dispose()
      resizeObserver.disconnect()
      registerTerminal(null)
      terminal.dispose()
      showInputPromptRef.current = null
      terminalRef.current = null
      fitAddonRef.current = null
    }
  }, [completeTab, fitTerminal, registerTerminal, runCommand])

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
    fitTerminal()
  }, [fitTerminal, fullscreen, height, open])

  const handleResizeMouseDown = useCallback((event: ReactMouseEvent) => {
    event.preventDefault()
    setIsResizing(true)
  }, [])

  useEffect(() => {
    if (!isResizing || fullscreen) return

    const handleMouseMove = (event: MouseEvent) => {
      if (!panelRef.current) return
      const panelBottom = panelRef.current.getBoundingClientRect().bottom
      setHeight(panelBottom - event.clientY)
    }

    const handleMouseUp = () => {
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

  return (
    <div
      ref={panelRef}
      className={cn(
        'relative flex min-h-0 flex-col overflow-hidden bg-background',
        fullscreen && 'h-full min-h-0 flex-1',
      )}
      style={fullscreen ? undefined : { height: `${height}px` }}
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
        <div className="flex min-w-0 items-center gap-2">
          <span className="flex size-7 shrink-0 items-center justify-center rounded-md border border-border bg-muted/50 text-muted-foreground">
            <TerminalIcon className="h-3.5 w-3.5" />
          </span>
          <span className="truncate text-[13px] font-semibold text-foreground">
            Terminal
          </span>
          {isBootstrapping ? (
            <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin text-muted-foreground" />
          ) : null}
        </div>

        <div className="flex shrink-0 items-center gap-0.5">
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
            'cli-terminal h-full min-h-0 overflow-hidden',
            '[&_.xterm]:h-full [&_.xterm-viewport]:!overflow-y-auto',
          )}
          onPointerDown={() => terminalRef.current?.focus()}
        />
      </div>
    </div>
  )
}
