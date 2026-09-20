import { useCallback, useEffect, useRef, useState } from 'react'
import {
  ChevronLeft,
  ChevronRight,
  GripHorizontal,
  X,
} from 'lucide-react'
import { useNavigate } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { DebugDemoCatalogPanel } from '@/components/global/providers/DebugDemoCatalogPanel'
import { DebugDemoNavigatorOptions } from '@/components/global/providers/DebugDemoNavigatorOptions'
import { DEFAULT_DEBUG_DEMO_ID } from '@/lib/debug-demos/catalog'
import { goToDemoSessionIndex, openDebugDemo } from '@/lib/debug-demos/navigate'
import {
  clampDebugDemoBarSize,
  clearDebugDemoSession,
  notifyDebugDemoSessionChange,
  readDebugDemoBarPosition,
  readDebugDemoBarSize,
  readDebugDemoSession,
  subscribeDebugDemoSession,
  writeDebugDemoBarPosition,
  writeDebugDemoBarSize,
  type DebugDemoBarPosition,
  type DebugDemoBarSize,
} from '@/lib/debug-demos/session'
import {
  DEBUG_MENU_HEADER_CLASS,
  DEBUG_MENU_ICON_BUTTON_CLASS,
  DEBUG_MENU_MUTED_TEXT,
  DEBUG_MENU_SHELL_CLASS,
} from '@/lib/debug-menu-chrome'
import { cn } from '@/lib/utils'

const DRAG_THRESHOLD_PX = 4

function clampBarPosition(
  position: DebugDemoBarPosition,
  barWidth: number,
  barHeight: number,
): DebugDemoBarPosition {
  const padding = 8
  const maxX = Math.max(padding, window.innerWidth - barWidth - padding)
  const maxY = Math.max(padding, window.innerHeight - barHeight - padding)
  return {
    x: Math.min(Math.max(padding, position.x), maxX),
    y: Math.min(Math.max(padding, position.y), maxY),
  }
}

type ResizeAxis = 'width' | 'height' | 'both'

export function DebugDemoNavigator() {
  const navigate = useNavigate()
  const [session, setSession] = useState(() => readDebugDemoSession())
  const barRef = useRef<HTMLDivElement>(null)
  const [barPosition, setBarPosition] = useState<DebugDemoBarPosition | null>(
    () => readDebugDemoBarPosition(),
  )
  const [barSize, setBarSize] = useState<DebugDemoBarSize>(() =>
    readDebugDemoBarSize(),
  )
  const dragStateRef = useRef<{
    pointerId: number
    startX: number
    startY: number
    originX: number
    originY: number
    moved: boolean
  } | null>(null)
  const resizeStateRef = useRef<{
    pointerId: number
    axis: ResizeAxis
    startX: number
    startY: number
    originWidth: number
    originHeight: number
  } | null>(null)

  useEffect(() => subscribeDebugDemoSession(() => setSession(readDebugDemoSession())), [])

  useEffect(() => {
    const handleResize = () => {
      setBarSize((prev) => clampDebugDemoBarSize(prev))
      setBarPosition((prev) => {
        if (!prev || !barRef.current) return prev
        const rect = barRef.current.getBoundingClientRect()
        return clampBarPosition(prev, rect.width, rect.height)
      })
    }
    window.addEventListener('resize', handleResize)
    return () => window.removeEventListener('resize', handleResize)
  }, [])

  const currentId = session.history[session.index] ?? DEFAULT_DEBUG_DEMO_ID

  const canGoBack = session.index > 0
  const canGoForward = session.index < session.history.length - 1

  const handleExit = useCallback(() => {
    clearDebugDemoSession()
    notifyDebugDemoSessionChange()
  }, [])

  const handleSelect = useCallback(
    (demoId: string) => {
      openDebugDemo(demoId, navigate)
    },
    [navigate],
  )

  const handleHistoryBack = useCallback(() => {
    if (!canGoBack) return
    goToDemoSessionIndex(session.index - 1, navigate)
  }, [canGoBack, navigate, session.index])

  const handleHistoryForward = useCallback(() => {
    if (!canGoForward) return
    goToDemoSessionIndex(session.index + 1, navigate)
  }, [canGoForward, navigate, session.index])

  const handleDragPointerDown = (
    event: React.PointerEvent<HTMLButtonElement>,
  ) => {
    const bar = barRef.current
    if (!bar) return

    const barRect = bar.getBoundingClientRect()
    const current =
      barPosition ??
      clampBarPosition(
        { x: barRect.left, y: barRect.top },
        barRect.width,
        barRect.height,
      )

    dragStateRef.current = {
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      originX: current.x,
      originY: current.y,
      moved: false,
    }
    setBarPosition(current)
    event.currentTarget.setPointerCapture(event.pointerId)
  }

  const handleDragPointerMove = (
    event: React.PointerEvent<HTMLButtonElement>,
  ) => {
    const dragState = dragStateRef.current
    const bar = barRef.current
    if (!dragState || dragState.pointerId !== event.pointerId || !bar) return

    const deltaX = event.clientX - dragState.startX
    const deltaY = event.clientY - dragState.startY
    if (
      !dragState.moved &&
      Math.hypot(deltaX, deltaY) >= DRAG_THRESHOLD_PX
    ) {
      dragState.moved = true
    }
    if (!dragState.moved) return

    const barRect = bar.getBoundingClientRect()
    setBarPosition(
      clampBarPosition(
        {
          x: dragState.originX + deltaX,
          y: dragState.originY + deltaY,
        },
        barRect.width,
        barRect.height,
      ),
    )
  }

  const finishDrag = (event: React.PointerEvent<HTMLButtonElement>) => {
    const dragState = dragStateRef.current
    if (!dragState || dragState.pointerId !== event.pointerId) return
    dragStateRef.current = null
    if (barPosition) {
      writeDebugDemoBarPosition(barPosition)
    }
    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId)
    }
  }

  const handleResizePointerDown = (
    event: React.PointerEvent<HTMLDivElement>,
    axis: ResizeAxis,
  ) => {
    event.preventDefault()
    event.stopPropagation()
    resizeStateRef.current = {
      pointerId: event.pointerId,
      axis,
      startX: event.clientX,
      startY: event.clientY,
      originWidth: barSize.width,
      originHeight: barSize.height,
    }
    event.currentTarget.setPointerCapture(event.pointerId)
  }

  const handleResizePointerMove = (
    event: React.PointerEvent<HTMLDivElement>,
  ) => {
    const resizeState = resizeStateRef.current
    if (!resizeState || resizeState.pointerId !== event.pointerId) return

    const deltaX = event.clientX - resizeState.startX
    const deltaY = event.clientY - resizeState.startY

    let nextWidth = resizeState.originWidth
    let nextHeight = resizeState.originHeight

    if (resizeState.axis === 'width' || resizeState.axis === 'both') {
      nextWidth = resizeState.originWidth - deltaX
    }
    if (resizeState.axis === 'height' || resizeState.axis === 'both') {
      nextHeight = resizeState.originHeight + deltaY
    }

    const clamped = clampDebugDemoBarSize({
      width: nextWidth,
      height: nextHeight,
    })
    setBarSize(clamped)

    if (barPosition && barRef.current) {
      setBarPosition(
        clampBarPosition(
          barPosition,
          clamped.width,
          clamped.height,
        ),
      )
    }
  }

  const finishResize = (event: React.PointerEvent<HTMLDivElement>) => {
    const resizeState = resizeStateRef.current
    if (!resizeState || resizeState.pointerId !== event.pointerId) return
    resizeStateRef.current = null
    writeDebugDemoBarSize(barSize)
    if (barPosition) {
      writeDebugDemoBarPosition(barPosition)
    }
    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId)
    }
  }

  if (!session.active) return null

  return (
    <div
      ref={barRef}
      dir="ltr"
      lang="en"
      className={cn(
        'fixed z-[10055]',
        DEBUG_MENU_SHELL_CLASS,
        barPosition === null && 'end-4 top-1/2 -translate-y-1/2',
      )}
      style={{
        width: barSize.width,
        height: barSize.height,
        ...(barPosition
          ? { left: barPosition.x, top: barPosition.y, transform: 'none' }
          : {}),
      }}
      role="toolbar"
      aria-label="Debug demo navigator"
    >
      <div
        className={cn(
          'flex shrink-0 items-center gap-1 border-b px-2 py-2',
          DEBUG_MENU_HEADER_CLASS,
        )}
      >
        <button
          type="button"
          className={cn(
            DEBUG_MENU_ICON_BUTTON_CLASS,
            'h-8 w-8 shrink-0 cursor-grab active:cursor-grabbing',
          )}
          aria-label="Drag demo navigator"
          onPointerDown={handleDragPointerDown}
          onPointerMove={handleDragPointerMove}
          onPointerUp={finishDrag}
          onPointerCancel={finishDrag}
        >
          <GripHorizontal className="h-4 w-4" aria-hidden />
        </button>
        <p className="min-w-0 flex-1 truncate text-[13px] font-semibold text-foreground">
          Demo pages
        </p>
        <Button
          type="button"
          variant="outline"
          size="icon"
          className={cn(
            'h-8 w-8 shrink-0',
            'border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))]',
          )}
          onClick={handleExit}
          aria-label="Exit demo mode"
        >
          <X className="h-4 w-4" />
        </Button>
      </div>

      <div
        className={cn(
          'flex shrink-0 items-center gap-1 border-b px-2 py-1.5',
          DEBUG_MENU_HEADER_CLASS,
        )}
      >
        <span
          className={cn(
            'text-[10px] font-medium uppercase tracking-wider',
            DEBUG_MENU_MUTED_TEXT,
          )}
        >
          History
        </span>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-7 w-7 text-[var(--network-globe-edge)] hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground"
          disabled={!canGoBack}
          onClick={handleHistoryBack}
          aria-label="Previous in history"
        >
          <ChevronLeft className="h-3.5 w-3.5" />
        </Button>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-7 w-7 text-[var(--network-globe-edge)] hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground"
          disabled={!canGoForward}
          onClick={handleHistoryForward}
          aria-label="Next in history"
        >
          <ChevronRight className="h-3.5 w-3.5" />
        </Button>
        <span
          className={cn('ms-auto text-[10px] tabular-nums', DEBUG_MENU_MUTED_TEXT)}
        >
          {session.history.length > 0
            ? `${session.index + 1} / ${session.history.length}`
            : null}
        </span>
      </div>

      <DebugDemoNavigatorOptions currentDemoId={currentId} />

      <DebugDemoCatalogPanel
        currentId={currentId}
        onSelect={handleSelect}
        density="panel"
        showCategoryFilter
        className="min-h-0 flex-1"
      />

      <div
        role="separator"
        aria-orientation="vertical"
        aria-label="Resize width"
        className="absolute bottom-8 left-0 top-8 w-1.5 cursor-ew-resize touch-none"
        onPointerDown={(event) => handleResizePointerDown(event, 'width')}
        onPointerMove={handleResizePointerMove}
        onPointerUp={finishResize}
        onPointerCancel={finishResize}
      />
      <div
        role="separator"
        aria-orientation="horizontal"
        aria-label="Resize height"
        className="absolute bottom-0 left-2 right-2 h-1.5 cursor-ns-resize touch-none"
        onPointerDown={(event) => handleResizePointerDown(event, 'height')}
        onPointerMove={handleResizePointerMove}
        onPointerUp={finishResize}
        onPointerCancel={finishResize}
      />
      <div
        aria-label="Resize width and height"
        className="absolute bottom-0 left-0 h-4 w-4 cursor-nesw-resize touch-none"
        onPointerDown={(event) => handleResizePointerDown(event, 'both')}
        onPointerMove={handleResizePointerMove}
        onPointerUp={finishResize}
        onPointerCancel={finishResize}
      />
    </div>
  )
}
