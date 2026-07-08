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
import { setBodyResizeDragActive } from '@/lib/layout/horizontal-resize'
import { useT } from '@/lib/i18n/translate'
import { usePlatform } from '@/hooks/use-keyboard-shortcuts'
import {
  CLI_SHELL_COLLAPSED_HEIGHT_PX,
  CLI_SHELL_COLLAPSE_MS,
} from '@/lib/cli-shell/constants'
import { CLI_SHELL_NEW_TERMINAL_SHORTCUT_RAW } from '@/lib/cli-shell/cli-terminal-shortcuts'
import { formatDisplayKeys } from '@/lib/keyboard-shortcuts/display'
import { CliSessionSidebar } from './CliSessionSidebar'
import { CliTerminalResizableLayout } from './CliTerminalResizableLayout'
import { CliTerminalSearch } from './CliTerminalSearch'
import { CliTerminalSessionsLayout } from './CliTerminalSessionsLayout'
import { useCliShell } from './CliShellProvider'

export function ProjectCliShell() {
  const { open, fullscreen, height, panelEverOpened } = useCliShell()
  const [isCollapsing, setIsCollapsing] = useState(false)
  const [isResizing, setIsResizing] = useState(false)
  const prevOpenRef = useRef(open)

  useEffect(() => {
    const wasOpen = prevOpenRef.current
    prevOpenRef.current = open

    if (open) {
      setIsCollapsing(false)
      return
    }

    if (!panelEverOpened || !wasOpen) return

    setIsCollapsing(true)
    const timer = window.setTimeout(
      () => setIsCollapsing(false),
      CLI_SHELL_COLLAPSE_MS,
    )
    return () => window.clearTimeout(timer)
  }, [open, panelEverOpened])

  const showCollapsedBar = !open && !isCollapsing
  const isFullyCollapsed = showCollapsedBar && !fullscreen
  const isFullscreenOpen = open && fullscreen
  const containerHeight =
    open && !fullscreen ? height : CLI_SHELL_COLLAPSED_HEIGHT_PX

  return (
    <div
      data-cli-shell
      className={cn(
        'bg-background',
        isFullscreenOpen
          ? 'fixed inset-0 z-[140] flex min-h-0 flex-col'
          : cn(
              'relative shrink-0 overflow-hidden border-t border-border',
              !isResizing && 'transition-[height] ease-in-out',
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
      {panelEverOpened ? (
        <div
          className={cn(
            'flex min-h-0 flex-col',
            isFullscreenOpen ? 'h-full flex-1' : undefined,
            !isFullscreenOpen && !open && 'pointer-events-none',
            isFullyCollapsed && 'opacity-0',
          )}
          style={
            isFullscreenOpen
              ? undefined
              : {
                  height,
                  minHeight: height,
                }
          }
          aria-hidden={!isFullscreenOpen && !open}
        >
          <ProjectCliShellPanel onResizingChange={setIsResizing} />
        </div>
      ) : null}
      {showCollapsedBar ? (
        <div className="absolute inset-0 z-10 flex min-h-0 flex-col">
          <ProjectCliShellCollapsedBar />
        </div>
      ) : null}
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
  const t = useT()
  return (
    <div className="flex min-w-0 items-center gap-2">
      <TerminalIcon
        className="h-3.5 w-3.5 shrink-0 text-muted-foreground rtl:-scale-x-100"
        strokeWidth={2.5}
        aria-hidden="true"
      />
      <div className="flex min-w-0 items-center gap-2">
        <span className="truncate text-[13px] font-semibold text-foreground">
          {t('Terminal')}
        </span>
        {showBootstrapSpinner ? (
          <Loader2
            className="h-3.5 w-3.5 shrink-0 animate-spin text-muted-foreground"
            aria-label={t('Setting up CLI')}
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
            {t('Retry setup')}
          </Button>
        ) : null}
      </div>
    </div>
  )
}

function ProjectCliShellCollapsedBar() {
  const t = useT()
  const { setOpen, status } = useCliShell()
  const isBootstrapping = status === 'bootstrapping'

  return (
    <button
      type="button"
      onClick={() => setOpen(true)}
      className="flex h-full w-full cursor-pointer items-center justify-between gap-4 bg-background px-4 text-start transition-colors hover:bg-muted/40 sm:px-6"
      aria-label={t('Open terminal')}
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
  const t = useT()
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
  const [isSidebarResizing, setIsSidebarResizing] = useState(false)
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
      setBodyResizeDragActive(true, 'row-resize')
      setIsResizing(true)
    },
    [height],
  )

  useEffect(() => {
    onResizingChange?.(isResizing || isSplitResizing || isSidebarResizing)
  }, [isResizing, isSplitResizing, isSidebarResizing, onResizingChange])

  useEffect(() => {
    if (!isResizing || fullscreen) return

    const handleMouseMove = (event: MouseEvent) => {
      const session = resizeSessionRef.current
      if (!session) return
      setHeight(session.startHeight + (session.startY - event.clientY))
    }

    const handleMouseUp = () => {
      resizeSessionRef.current = null
      setBodyResizeDragActive(false)
      setIsResizing(false)
    }

    document.addEventListener('mousemove', handleMouseMove)
    document.addEventListener('mouseup', handleMouseUp)

    return () => {
      document.removeEventListener('mousemove', handleMouseMove)
      document.removeEventListener('mouseup', handleMouseUp)
      setBodyResizeDragActive(false)
    }
  }, [fullscreen, isResizing, setHeight])

  const isBootstrapping = status === 'bootstrapping'

  const [headerActionsVisible, setHeaderActionsVisible] = useState(open)

  useEffect(() => {
    setHeaderActionsVisible(open)
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
          aria-label={t('Resize terminal')}
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
          showRetry={status === 'error' || status === 'bootstrapping' || !!bootstrapError}
          onRetry={retryBootstrap}
        />

        <div
          className={cn(
            'flex shrink-0 items-center',
            headerActionsVisible
              ? 'opacity-100'
              : 'pointer-events-none opacity-0',
          )}
        >
          <CliShellHeaderIconButton
            title={`${t('New terminal')} (${newTerminalShortcutKeys})`}
            onClick={createSession}
          >
            <Plus className="h-3.5 w-3.5" />
          </CliShellHeaderIconButton>

          <CliShellHeaderIconButton
            title={
              terminalSearchOpen
                ? t('Close search')
                : `${t('Search output...')} (⌘F)`
            }
            aria-label={
              terminalSearchOpen
                ? t('Close terminal search')
                : t('Search terminal output')
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
                title={t('Output actions')}
                aria-label={t('Output actions')}
              >
                <Ellipsis className="h-3.5 w-3.5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-48">
              <DropdownMenuLabel className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Copy')}
              </DropdownMenuLabel>
              <DropdownMenuItem onClick={copyTerminalSelection}>
                {t('Copy selection')}
              </DropdownMenuItem>
              <DropdownMenuItem onClick={copyLastCommand}>
                {t('Copy last command')}
              </DropdownMenuItem>
              <DropdownMenuItem onClick={copyTerminalOutput}>
                {t('Copy all output')}
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuLabel className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Output')}
              </DropdownMenuLabel>
              <DropdownMenuItem
                onClick={exportTerminalOutput}
                className="gap-1.5"
              >
                <Download className="h-3.5 w-3.5" />
                {t('Download output')}
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => clearOutput(focusedSessionId)}
                className="gap-1.5"
              >
                <Trash2 className="h-3.5 w-3.5" />
                {t('Clear output')}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>

          <CliShellHeaderDivider />

          <CliShellHeaderIconButton
            title={fullscreen ? t('Exit full screen') : t('Full screen')}
            onClick={toggleFullscreen}
          >
            {fullscreen ? (
              <Minimize2 className="h-3.5 w-3.5" />
            ) : (
              <Maximize2 className="h-3.5 w-3.5" />
            )}
          </CliShellHeaderIconButton>
          <CliShellHeaderIconButton title={t('Minimize shell')} onClick={toggle}>
            <ChevronDown className="h-3.5 w-3.5" />
          </CliShellHeaderIconButton>
        </div>
      </div>

      <CliTerminalResizableLayout
        onSidebarResizingChange={setIsSidebarResizing}
        main={
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
                  : 'px-4 pt-3 pb-3 sm:px-6',
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
                  isPanelResizing={
                    isResizing || isSplitResizing || isSidebarResizing
                  }
                  onSplitResizingChange={setIsSplitResizing}
                />
              </div>
            </div>
          </div>
        }
        sidebar={<CliSessionSidebar />}
      />
    </div>
  )
}
