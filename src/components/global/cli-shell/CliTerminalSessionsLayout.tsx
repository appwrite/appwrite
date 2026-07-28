import {
  Fragment,
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import type { ImperativePanelHandle } from 'react-resizable-panels'
import {
  ResizableHandle,
  ResizablePanel,
  ResizablePanelGroup,
} from '@/components/ui/resizable'
import {
  RESIZE_HANDLE_PSEUDO_AFTER_X,
  RESIZE_HANDLE_PSEUDO_BEFORE_X,
} from '@/lib/layout/horizontal-resize'
import { cn } from '@/lib/utils'
import type { CliShellSession } from '@/lib/cli-shell/cli-shell-sessions'
import { useCliShell } from './CliShellProvider'
import { CliTerminalSession } from './CliTerminalSession'

const SPLIT_HANDLE_CLASS = cn(
  'relative z-10 h-full w-px shrink-0 self-stretch items-stretch bg-border',
  'before:pointer-events-none before:absolute before:inset-y-0 before:w-2 before:bg-border before:opacity-0 before:transition-opacity',
  RESIZE_HANDLE_PSEUDO_BEFORE_X,
  'hover:before:opacity-100 data-[resize-handle-state=hover]:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
  'after:absolute after:inset-y-0 after:w-3',
  RESIZE_HANDLE_PSEUDO_AFTER_X,
)

function getSplitPaneContentClass(index: number, total: number): string {
  if (total <= 1) return ''

  const beforeSeparator = 'pe-3'
  const afterSeparator = 'ps-5 sm:ps-6'

  if (index === 0) {
    return cn('ps-4 sm:ps-6', total > 1 && beforeSeparator)
  }
  if (index === total - 1) {
    return cn(afterSeparator, 'pe-4 sm:pe-6')
  }
  return cn(afterSeparator, beforeSeparator)
}

type CliTerminalSessionsLayoutProps = {
  sessions: CliShellSession[]
  displaySessionIds: string[]
  focusedSessionId: string
  isPanelResizing?: boolean
  onSplitResizingChange?: (isResizing: boolean) => void
}

export function CliTerminalSessionsLayout({
  sessions,
  displaySessionIds,
  focusedSessionId,
  isPanelResizing = false,
  onSplitResizingChange,
}: CliTerminalSessionsLayoutProps) {
  const { open } = useCliShell()
  const [isSplitResizing, setIsSplitResizing] = useState(false)
  const [activatedSessions, setActivatedSessions] = useState<
    Map<string, { deferInit: boolean }>
  >(() => new Map())
  const panelOpenRef = useRef(false)
  const panelRefs = useRef<Record<string, ImperativePanelHandle | null>>({})
  const effectiveDisplaySessionIds = useMemo(() => {
    const valid = displaySessionIds.filter((id) =>
      sessions.some((session) => session.id === id),
    )
    if (valid.length > 0) return valid
    if (sessions.length > 0) return [sessions[0]!.id]
    return []
  }, [displaySessionIds, sessions])
  const isSplit = effectiveDisplaySessionIds.length > 1
  const displayedCount = effectiveDisplaySessionIds.length
  const defaultPaneSize =
    displayedCount > 0 ? 100 / displayedCount : 100
  const isResizing = isPanelResizing || isSplitResizing

  const handleSplitDragging = useCallback(
    (dragging: boolean) => {
      setIsSplitResizing(dragging)
      onSplitResizingChange?.(dragging)
    },
    [onSplitResizingChange],
  )

  const handleSplitLayout = useCallback(() => {
    if (typeof window === 'undefined') return
    window.dispatchEvent(new Event('resize'))
  }, [])

  useEffect(() => {
    handleSplitLayout()
  }, [effectiveDisplaySessionIds, handleSplitLayout])

  useEffect(() => {
    const wasOpen = panelOpenRef.current
    panelOpenRef.current = open
    const deferInitForNewSessions = open && !wasOpen

    setActivatedSessions((previous) => {
      const next = new Map(previous)
      let changed = false
      for (const sessionId of effectiveDisplaySessionIds) {
        if (!next.has(sessionId)) {
          next.set(sessionId, { deferInit: deferInitForNewSessions })
          changed = true
        }
      }
      return changed ? next : previous
    })
  }, [effectiveDisplaySessionIds, open])

  useLayoutEffect(() => {
    const activeDisplayId =
      effectiveDisplaySessionIds.length === 1
        ? effectiveDisplaySessionIds[0]
        : null
    const splitSize = isSplit ? 100 / displayedCount : 100

    for (const session of sessions) {
      const panel = panelRefs.current[session.id]
      if (!panel) continue

      const isDisplayed = effectiveDisplaySessionIds.includes(session.id)
      if (!isDisplayed) {
        if (!panel.isCollapsed()) {
          panel.collapse()
        }
        continue
      }

      if (panel.isCollapsed()) {
        panel.expand()
      }

      if (isSplit) {
        const currentSize = panel.getSize()
        if (Math.abs(currentSize - splitSize) > 0.5) {
          panel.resize(splitSize)
        }
      } else if (activeDisplayId === session.id) {
        if (panel.getSize() < 99) {
          panel.resize(100)
        }
      } else if (!panel.isCollapsed()) {
        panel.collapse()
      }
    }
  }, [displayedCount, effectiveDisplaySessionIds, isSplit, sessions])

  if (effectiveDisplaySessionIds.length === 0) {
    return <div className="h-full min-h-0" aria-hidden />
  }

  return (
    <ResizablePanelGroup
      direction="horizontal"
      className="h-full min-h-0 min-w-0 items-stretch gap-0"
      onLayout={handleSplitLayout}
    >
      {sessions.map((session, index) => {
        const isDisplayed = effectiveDisplaySessionIds.includes(session.id)
        const displayIndex = effectiveDisplaySessionIds.indexOf(session.id)
        const hasDisplayedBeforeInList = sessions
          .slice(0, index)
          .some((item) => effectiveDisplaySessionIds.includes(item.id))
        const showHandle =
          isSplit &&
          isDisplayed &&
          displayIndex > 0 &&
          hasDisplayedBeforeInList

        const activation = activatedSessions.get(session.id)

        return (
          <Fragment key={session.id}>
            {showHandle ? (
              <ResizableHandle
                className={SPLIT_HANDLE_CLASS}
                onDragging={handleSplitDragging}
              />
            ) : null}
            <ResizablePanel
              id={session.id}
              order={index + 1}
              ref={(panel) => {
                panelRefs.current[session.id] = panel
              }}
              defaultSize={
                isDisplayed ? (isSplit ? defaultPaneSize : 100) : 0
              }
              minSize={isDisplayed ? 12 : 0}
              collapsible
              collapsedSize={0}
              className={cn(
                'flex min-h-0 min-w-0 flex-col',
                !isDisplayed && 'min-w-0 max-w-0 overflow-hidden',
              )}
            >
              <div
                className={cn(
                  'flex h-full min-h-0 flex-col',
                  isSplit && isDisplayed && 'pt-3 pb-3',
                )}
              >
                <div
                  className={cn(
                    'box-border flex min-h-0 w-full min-w-0 flex-1 flex-col overflow-hidden',
                    isSplit &&
                      isDisplayed &&
                      getSplitPaneContentClass(displayIndex, displayedCount),
                  )}
                >
                  {activation ? (
                    <CliTerminalSession
                      sessionId={session.id}
                      isVisible={isDisplayed}
                      isFocused={focusedSessionId === session.id}
                      isPanelResizing={isResizing}
                      isSplitPane={isSplit && isDisplayed}
                      deferInit={activation.deferInit}
                    />
                  ) : (
                    <div className="h-full min-h-0" aria-hidden="true" />
                  )}
                </div>
              </div>
            </ResizablePanel>
          </Fragment>
        )
      })}
    </ResizablePanelGroup>
  )
}
