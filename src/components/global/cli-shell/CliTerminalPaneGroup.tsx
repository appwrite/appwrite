import { Fragment, useCallback, useEffect, useState } from 'react'
import {
  ResizableHandle,
  ResizablePanel,
  ResizablePanelGroup,
} from '@/components/ui/resizable'
import { cn } from '@/lib/utils'
import { CliTerminalSession } from './CliTerminalSession'

const SPLIT_HANDLE_CLASS = cn(
  'relative z-10 h-full w-px shrink-0 self-stretch items-stretch bg-border',
  'before:pointer-events-none before:absolute before:inset-y-0 before:left-1/2 before:w-2 before:-translate-x-1/2 before:bg-border before:opacity-0 before:transition-opacity',
  'hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
  'after:absolute after:inset-y-0 after:left-1/2 after:w-3 after:-translate-x-1/2',
)

function getSplitPaneContentClass(index: number, total: number): string {
  if (total <= 1) return ''

  // Gutter on one side per separator so we don't double-stack padding at splits.
  const beforeSeparator = 'pr-3'
  const afterSeparator = 'pl-3'

  if (index === 0) {
    return cn('pl-4 sm:pl-6', total > 1 && beforeSeparator)
  }
  if (index === total - 1) {
    return cn(afterSeparator, 'pr-4 sm:pr-6')
  }
  return cn(afterSeparator, beforeSeparator)
}

type CliTerminalPaneGroupProps = {
  paneSessionIds: string[]
  focusedSessionId: string
  isPanelResizing?: boolean
  onSplitResizingChange?: (isResizing: boolean) => void
}

export function CliTerminalPaneGroup({
  paneSessionIds,
  focusedSessionId,
  isPanelResizing = false,
  onSplitResizingChange,
}: CliTerminalPaneGroupProps) {
  const [isSplitResizing, setIsSplitResizing] = useState(false)
  const paneCount = paneSessionIds.length
  const defaultPaneSize = paneCount > 0 ? 100 / paneCount : 100

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

  const isResizing = isPanelResizing || isSplitResizing

  useEffect(() => {
    if (!isSplitResizing) return
    document.body.style.cursor = 'col-resize'
    document.body.style.userSelect = 'none'
    return () => {
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }
  }, [isSplitResizing])

  return (
    <ResizablePanelGroup
      direction="horizontal"
      className="h-full min-h-0 min-w-0 items-stretch gap-0"
      onLayout={handleSplitLayout}
    >
      {paneSessionIds.map((sessionId, index) => (
        <Fragment key={sessionId}>
          {index > 0 ? (
            <ResizableHandle
              className={SPLIT_HANDLE_CLASS}
              onDragging={handleSplitDragging}
            />
          ) : null}
          <ResizablePanel
            defaultSize={defaultPaneSize}
            minSize={12}
            className="flex min-h-0 min-w-0 flex-col"
          >
            <div className="flex h-full min-h-0 flex-col py-3 sm:pb-4">
              <div
                className={cn(
                  'box-border flex min-h-0 w-full min-w-0 flex-1 flex-col overflow-hidden',
                  getSplitPaneContentClass(index, paneSessionIds.length),
                )}
              >
                <CliTerminalSession
                  sessionId={sessionId}
                  isVisible={true}
                  isFocused={focusedSessionId === sessionId}
                  isPanelResizing={isResizing}
                  isSplitPane={true}
                />
              </div>
            </div>
          </ResizablePanel>
        </Fragment>
      ))}
    </ResizablePanelGroup>
  )
}
