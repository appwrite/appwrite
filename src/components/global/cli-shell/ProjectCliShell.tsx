import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type MouseEvent as ReactMouseEvent,
} from 'react'
import {
  Loader2,
  Maximize2,
  Minimize2,
  Plus,
  RotateCcw,
  Terminal as TerminalIcon,
  Trash2,
  ChevronDown,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { CLI_SHELL_COLLAPSED_HEIGHT_PX } from '@/lib/cli-shell/constants'
import { CliSessionSidebar } from './CliSessionSidebar'
import { CliTerminalSession } from './CliTerminalSession'
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
      <TerminalIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" strokeWidth={3} />
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
    toggle,
    status,
    sessions,
    activeSessionId,
    clearOutput,
    createSession,
    height,
    setHeight,
    retryBootstrap,
    bootstrapError,
    fullscreen,
    toggleFullscreen,
  } = useCliShell()

  const [isResizing, setIsResizing] = useState(false)
  const resizeSessionRef = useRef<{
    startY: number
    startHeight: number
  } | null>(null)

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
            onClick={createSession}
            title="New terminal"
            aria-label="New terminal"
          >
            <Plus className="h-3.5 w-3.5" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="h-8 w-8 text-muted-foreground"
            onClick={() => clearOutput(activeSessionId)}
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
        </div>
      </div>

      <div className="flex min-h-0 flex-1 overflow-hidden">
        <div className="min-h-0 flex-1 overflow-hidden px-4 pb-4 pt-3 sm:px-6 sm:pb-4">
          <div className="relative h-full min-h-0">
            {sessions.map((session) => (
              <CliTerminalSession
                key={session.id}
                sessionId={session.id}
                isActive={session.id === activeSessionId}
                isPanelResizing={isResizing}
              />
            ))}
          </div>
        </div>
        <CliSessionSidebar />
      </div>
    </div>
  )
}
