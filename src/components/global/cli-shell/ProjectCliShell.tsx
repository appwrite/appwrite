import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type MouseEvent as ReactMouseEvent,
  type ReactNode,
} from 'react'
import {
  Download,
  Ellipsis,
  Loader2,
  Maximize2,
  Minimize2,
  Plus,
  RotateCcw,
  Search,
  Terminal as TerminalIcon,
  Trash2,
  ChevronDown,
  ChevronUp,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { cn } from '@/lib/utils'
import { usePlatform } from '@/hooks/use-keyboard-shortcuts'
import { CLI_SHELL_COLLAPSED_HEIGHT_PX } from '@/lib/cli-shell/constants'
import { CLI_SHELL_NEW_TERMINAL_SHORTCUT_RAW } from '@/lib/cli-shell/cli-terminal-shortcuts'
import { formatDisplayKeys } from '@/lib/keyboard-shortcuts/display'
import { CliSessionSidebar } from './CliSessionSidebar'
import { CliTerminalSearch } from './CliTerminalSearch'
import { CliTerminalSessionsLayout } from './CliTerminalSessionsLayout'
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

function CliShellHeaderDivider() {
  return <div className="mx-0.5 h-4 w-px shrink-0 bg-border" aria-hidden="true" />
}

function CliShellHeaderIconButton({
  title,
  'aria-label': ariaLabel,
  onClick,
  pressed,
  children,
}: {
  title: string
  'aria-label'?: string
  onClick: () => void
  pressed?: boolean
  children: ReactNode
}) {
  return (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      className={cn(
        'h-8 w-8 shrink-0 text-muted-foreground',
        pressed && 'bg-muted text-foreground',
      )}
      onClick={onClick}
      title={title}
      aria-label={ariaLabel ?? title}
      aria-pressed={pressed}
    >
      {children}
    </Button>
  )
}

function CliShellHeaderTitle({
  showBootstrapSpinner = false,
  showRetry = false,
  onRetry,
}: {
  showBootstrapSpinner?: boolean
  showRetry?: boolean
  onRetry?: () => void
}) {
  return (
    <div className="flex min-w-0 items-center gap-2">
      <TerminalIcon
        className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
        strokeWidth={2.5}
        aria-hidden="true"
      />
      <div className="flex min-w-0 items-center gap-2">
        <span className="truncate text-[13px] font-semibold text-foreground">
          Terminal
        </span>
        {showBootstrapSpinner ? (
          <Loader2
            className="h-3.5 w-3.5 shrink-0 animate-spin text-muted-foreground"
            aria-label="Setting up CLI"
          />
        ) : null}
        {showRetry ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-7 shrink-0 gap-1.5 px-2 text-[12px] text-destructive hover:text-destructive"
            onClick={onRetry}
          >
            <RotateCcw className="h-3 w-3" />
            Retry setup
          </Button>
        ) : null}
      </div>
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
      className="flex h-full w-full cursor-pointer items-center justify-between gap-4 bg-card/50 px-4 text-left transition-colors hover:bg-muted/40 sm:px-6"
      aria-label="Open terminal"
    >
      <CliShellHeaderTitle showBootstrapSpinner={isBootstrapping} />
      <span
        className="flex h-8 w-8 shrink-0 items-center justify-center text-muted-foreground"
        aria-hidden="true"
      >
        <ChevronUp className="h-3.5 w-3.5" />
      </span>
    </button>
  )
}

type ProjectCliShellPanelProps = {
  onResizingChange?: (isResizing: boolean) => void
}

function ProjectCliShellPanel({ onResizingChange }: ProjectCliShellPanelProps) {
  const { isMac } = usePlatform()
  const newTerminalShortcutKeys = formatDisplayKeys(
    CLI_SHELL_NEW_TERMINAL_SHORTCUT_RAW,
    isMac,
  ).join('')
  const {
    open,
    toggle,
    status,
    sessions,
    activeSessionId,
    focusedSessionId,
    visiblePaneSessionIds,
    clearOutput,
    createSession,
    height,
    setHeight,
    retryBootstrap,
    bootstrapError,
    fullscreen,
    toggleFullscreen,
    terminalSearchOpen,
    setTerminalSearchOpen,
    terminalSearchResults,
    toggleTerminalSearch,
    searchTerminalOutput,
    findNextTerminalMatch,
    findPreviousTerminalMatch,
    copyTerminalSelection,
    copyLastCommand,
    copyTerminalOutput,
    exportTerminalOutput,
  } = useCliShell()

  const [isResizing, setIsResizing] = useState(false)
  const [isSplitResizing, setIsSplitResizing] = useState(false)
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
    onResizingChange?.(isResizing || isSplitResizing)
  }, [isResizing, isSplitResizing, onResizingChange])

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

      <div
        className="flex h-11 shrink-0 items-center justify-between gap-4 border-b border-border bg-muted/20 px-4 sm:px-6"
      >
        <CliShellHeaderTitle
          showBootstrapSpinner={isBootstrapping}
          showRetry={status === 'error' || !!bootstrapError}
          onRetry={retryBootstrap}
        />

        <div
          className={cn(
            'flex shrink-0 items-center transition-opacity ease-out',
            headerActionsVisible
              ? 'opacity-100'
              : 'pointer-events-none opacity-0',
          )}
          style={{ transitionDuration: `${CLI_SHELL_COLLAPSE_MS}ms` }}
        >
          <CliShellHeaderIconButton
            title={`New terminal (${newTerminalShortcutKeys})`}
            onClick={createSession}
          >
            <Plus className="h-3.5 w-3.5" />
          </CliShellHeaderIconButton>

          <CliShellHeaderIconButton
            title={
              terminalSearchOpen ? 'Close search' : 'Search output (⌘F)'
            }
            aria-label={
              terminalSearchOpen
                ? 'Close terminal search'
                : 'Search terminal output'
            }
            pressed={terminalSearchOpen}
            onClick={toggleTerminalSearch}
          >
            <Search className="h-3.5 w-3.5" />
          </CliShellHeaderIconButton>

          <CliShellHeaderDivider />

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-8 w-8 shrink-0 text-muted-foreground"
                title="Output actions"
                aria-label="Output actions"
              >
                <Ellipsis className="h-3.5 w-3.5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-48">
              <DropdownMenuLabel className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                Copy
              </DropdownMenuLabel>
              <DropdownMenuItem onClick={copyTerminalSelection}>
                Copy selection
              </DropdownMenuItem>
              <DropdownMenuItem onClick={copyLastCommand}>
                Copy last command
              </DropdownMenuItem>
              <DropdownMenuItem onClick={copyTerminalOutput}>
                Copy all output
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuLabel className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                Output
              </DropdownMenuLabel>
              <DropdownMenuItem
                onClick={exportTerminalOutput}
                className="gap-1.5"
              >
                <Download className="h-3.5 w-3.5" />
                Download output
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => clearOutput(focusedSessionId)}
                className="gap-1.5"
              >
                <Trash2 className="h-3.5 w-3.5" />
                Clear output
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>

          <CliShellHeaderDivider />

          <CliShellHeaderIconButton
            title={fullscreen ? 'Exit full screen' : 'Full screen'}
            onClick={toggleFullscreen}
          >
            {fullscreen ? (
              <Minimize2 className="h-3.5 w-3.5" />
            ) : (
              <Maximize2 className="h-3.5 w-3.5" />
            )}
          </CliShellHeaderIconButton>
          <CliShellHeaderIconButton title="Minimize shell" onClick={toggle}>
            <ChevronDown className="h-3.5 w-3.5" />
          </CliShellHeaderIconButton>
        </div>
      </div>

      <div className="flex min-h-0 flex-1 overflow-hidden">
        <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
          {terminalSearchOpen ? (
            <CliTerminalSearch
              open={true}
              onOpenChange={setTerminalSearchOpen}
              results={terminalSearchResults}
              onSearch={(query, options) =>
                searchTerminalOutput(query, options, focusedSessionId)
              }
              onFindNext={findNextTerminalMatch}
              onFindPrevious={findPreviousTerminalMatch}
            />
          ) : null}
          <div
            className={cn(
              'flex min-h-0 flex-1 flex-col overflow-hidden',
              visiblePaneSessionIds.length > 1
                ? 'px-0'
                : 'px-4 pb-4 pt-3 sm:px-6 sm:pb-4',
              terminalSearchOpen &&
                visiblePaneSessionIds.length <= 1 &&
                'pt-0',
            )}
          >
          <div className="h-full min-h-0 flex-1">
            <CliTerminalSessionsLayout
              sessions={sessions}
              displaySessionIds={
                visiblePaneSessionIds.length > 1
                  ? visiblePaneSessionIds
                  : [activeSessionId]
              }
              focusedSessionId={focusedSessionId}
              isPanelResizing={isResizing}
              onSplitResizingChange={setIsSplitResizing}
            />
          </div>
          </div>
        </div>
        <CliSessionSidebar />
      </div>
    </div>
  )
}
