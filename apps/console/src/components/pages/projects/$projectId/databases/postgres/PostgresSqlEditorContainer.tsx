import type { PointerEvent, ReactNode } from 'react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { usePostgresSqlEditorHeight } from '@/lib/react-query/hooks'
import {
  clampPostgresSqlEditorHeightPx,
  POSTGRES_SQL_EDITOR_DEFAULT_HEIGHT_PX,
  POSTGRES_SQL_EDITOR_MAX_HEIGHT_PX,
  POSTGRES_SQL_EDITOR_MIN_HEIGHT_PX,
  POSTGRES_SQL_RESULTS_MIN_HEIGHT_PX,
} from '@/lib/resizable-layout'
import { cn } from '@/lib/utils'
import { setBodyResizeDragActive } from '@/lib/layout/horizontal-resize'
import { useT } from '@/lib/i18n/translate'

/** Same vertical handle as API Explorer / `ResizableHandle` (hairline + 8px hit area). */
const HANDLE_CLASS = cn(
  'relative z-[45] flex h-px w-full shrink-0 items-center justify-center bg-border',
  'after:absolute after:inset-x-0 after:top-1/2 after:h-2 after:w-full after:-translate-y-1/2',
  'before:pointer-events-none before:absolute before:inset-x-0 before:top-1/2 before:h-2 before:w-full before:-translate-y-1/2 before:bg-border before:opacity-0 before:transition-opacity',
  'hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
  'cursor-row-resize touch-none',
)

type PostgresSqlEditorContainerProps = {
  editor: ReactNode
  children: ReactNode
  className?: string
}

function clampEditorHeightInContainer(
  heightPx: number,
  containerHeightPx: number,
): number {
  const clamped = clampPostgresSqlEditorHeightPx(heightPx)
  if (containerHeightPx <= 0) return clamped

  const maxInContainer = Math.min(
    POSTGRES_SQL_EDITOR_MAX_HEIGHT_PX,
    Math.max(
      POSTGRES_SQL_EDITOR_MIN_HEIGHT_PX,
      containerHeightPx - POSTGRES_SQL_RESULTS_MIN_HEIGHT_PX,
    ),
  )
  return Math.min(maxInContainer, Math.max(POSTGRES_SQL_EDITOR_MIN_HEIGHT_PX, clamped))
}

/**
 * SQL editor block with a draggable bottom edge (same handle styling as other
 * resizable layouts). Uses imperative height during drag to avoid Monaco flicker.
 */
export function PostgresSqlEditorContainer({
  editor,
  children,
  className,
}: PostgresSqlEditorContainerProps) {
  const t = useT()
  const containerRef = useRef<HTMLDivElement>(null)
  const editorPaneRef = useRef<HTMLDivElement>(null)
  const isDraggingRef = useRef(false)
  const dragStartYRef = useRef(0)
  const dragStartHeightRef = useRef(0)
  const latestHeightRef = useRef(0)

  const { account } = useAuth()
  const accountPrefs = account as { prefs?: Record<string, unknown> } | undefined
  const { heightPx: persistedHeightPx, persistEditorHeightPx } =
    usePostgresSqlEditorHeight(accountPrefs)

  const initialHeightPx = clampPostgresSqlEditorHeightPx(
    persistedHeightPx ?? POSTGRES_SQL_EDITOR_DEFAULT_HEIGHT_PX,
  )

  const [editorHeightPx, setEditorHeightPx] = useState(initialHeightPx)
  const [isDragging, setIsDragging] = useState(false)

  const getContainerHeight = useCallback(
    () => containerRef.current?.getBoundingClientRect().height ?? 0,
    [],
  )

  const applyEditorHeight = useCallback(
    (nextPx: number) => {
      const clamped = clampEditorHeightInContainer(nextPx, getContainerHeight())
      latestHeightRef.current = clamped
      if (editorPaneRef.current) {
        editorPaneRef.current.style.height = `${clamped}px`
      }
      return clamped
    },
    [getContainerHeight],
  )

  const handlePointerDown = useCallback(
    (e: PointerEvent<HTMLDivElement>) => {
      isDraggingRef.current = true
      setIsDragging(true)
      dragStartYRef.current = e.clientY
      dragStartHeightRef.current =
        editorPaneRef.current?.getBoundingClientRect().height ?? editorHeightPx
      latestHeightRef.current = dragStartHeightRef.current
      e.currentTarget.setPointerCapture(e.pointerId)
      setBodyResizeDragActive(true, 'row-resize')
    },
    [editorHeightPx],
  )

  const handlePointerMove = useCallback(
    (e: PointerEvent<HTMLDivElement>) => {
      if (!isDraggingRef.current) return
      const delta = e.clientY - dragStartYRef.current
      applyEditorHeight(dragStartHeightRef.current + delta)
    },
    [applyEditorHeight],
  )

  const finishDrag = useCallback(
    (e?: PointerEvent<HTMLDivElement>) => {
      if (!isDraggingRef.current) return
      isDraggingRef.current = false
      setIsDragging(false)
      setBodyResizeDragActive(false)

      if (e?.currentTarget.hasPointerCapture(e.pointerId)) {
        try {
          e.currentTarget.releasePointerCapture(e.pointerId)
        } catch {
          /* already released */
        }
      }

      const nextPx = latestHeightRef.current
      setEditorHeightPx(nextPx)
      persistEditorHeightPx(nextPx)
    },
    [persistEditorHeightPx],
  )

  useEffect(() => {
    return () => {
      setBodyResizeDragActive(false)
    }
  }, [])

  useEffect(() => {
    if (!isDragging) return

    const handleWindowPointerEnd = () => {
      finishDrag()
    }

    window.addEventListener('pointerup', handleWindowPointerEnd)
    window.addEventListener('pointercancel', handleWindowPointerEnd)
    return () => {
      window.removeEventListener('pointerup', handleWindowPointerEnd)
      window.removeEventListener('pointercancel', handleWindowPointerEnd)
    }
  }, [finishDrag, isDragging])

  return (
    <div
      ref={containerRef}
      className={cn('flex h-full min-h-0 min-w-0 flex-1 flex-col', className)}
    >
      <div
        ref={editorPaneRef}
        className="flex shrink-0 flex-col overflow-hidden bg-background"
        style={{ height: editorHeightPx }}
      >
        {editor}
      </div>
      <div
        role="separator"
        aria-orientation="horizontal"
        aria-label={t('Resize SQL editor')}
        data-resize-handle-state={isDragging ? 'drag' : 'idle'}
        className={HANDLE_CLASS}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={finishDrag}
        onPointerCancel={finishDrag}
      />
      <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
        {children}
      </div>
    </div>
  )
}
