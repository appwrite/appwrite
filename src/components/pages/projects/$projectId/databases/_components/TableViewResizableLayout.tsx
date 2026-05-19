import type { PointerEvent, ReactNode } from 'react'
import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import {
  ResizableHandle,
  ResizablePanel,
  ResizablePanelGroup,
} from '@/components/ui/resizable'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  useTableViewSidebarWidth,
  type TableViewSidebarWidthScope,
} from '@/lib/react-query/hooks'
import {
  parseDatabasesSidebarWidthPx,
  parseStorageSidebarWidthPx,
} from '@/lib/user-prefs-keys'
import {
  clampTableViewSidebarWidthPx,
  computeTwoPanelHorizontalLayout,
  TABLE_VIEW_MAIN_MIN_WIDTH_PX,
  TABLE_VIEW_SIDEBAR_DEFAULT_WIDTH_PX,
  TABLE_VIEW_SIDEBAR_MAX_WIDTH_PX,
  TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX,
} from '@/lib/resizable-layout'
import { cn } from '@/lib/utils'

/** Above rows grid stickies (`z-20`–`z-40` in View.tsx), below overlays (`z-50+`). */
const HANDLE_CLASS = cn(
  'relative z-[45] w-[0.5px] bg-border',
  'before:pointer-events-none before:absolute before:inset-y-0 before:left-1/2 before:w-2 before:-translate-x-1/2 before:bg-border before:opacity-0 before:transition-opacity',
  'hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
  'after:w-2 after:left-1/2 after:-translate-x-1/2',
)

/** Debounce window for persisting the sidebar width to user prefs. */
const PERSIST_DEBOUNCE_MS = 250

type TableViewResizableLayoutProps = {
  sidebar: ReactNode
  children: ReactNode
  className?: string
  /** Which account pref key to use for sidebar width persistence. */
  sidebarWidthScope?: TableViewSidebarWidthScope
}

/**
 * Left sub-navigation + main area with a draggable split, matching the
 * functions code editor and shared `ResizableHandle` styling.
 *
 * Sizes are defined and persisted in px; `react-resizable-panels` only accepts
 * %, so we measure the group and convert at runtime.
 */
export function TableViewResizableLayout({
  sidebar,
  children,
  className,
  sidebarWidthScope = 'databases',
}: TableViewResizableLayoutProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const [containerWidth, setContainerWidth] = useState(0)
  const { account } = useAuth()
  const accountPrefs = account as { prefs?: Record<string, unknown> } | undefined
  const { persistSidebarWidthPx } = useTableViewSidebarWidth(
    accountPrefs,
    sidebarWidthScope,
  )

  const parsePersistedSidebarPx =
    sidebarWidthScope === 'storage'
      ? parseStorageSidebarWidthPx
      : parseDatabasesSidebarWidthPx
  const sidebarWidthPx = useMemo(
    () =>
      clampTableViewSidebarWidthPx(
        parsePersistedSidebarPx(accountPrefs?.prefs) ??
          TABLE_VIEW_SIDEBAR_DEFAULT_WIDTH_PX,
      ),
    [accountPrefs?.prefs, parsePersistedSidebarPx],
  )

  /** Px used for `defaultSize` — set once the group is measured; updated after drag. */
  const [mountedSidebarPx, setMountedSidebarPx] = useState<number | null>(null)
  const isSidebarResizingRef = useRef(false)

  useLayoutEffect(() => {
    const el = containerRef.current
    if (!el) return
    const ro = new ResizeObserver((entries) => {
      const w = entries[0]?.contentRect.width ?? 0
      setContainerWidth(w)
    })
    ro.observe(el)
    setContainerWidth(el.getBoundingClientRect().width)
    return () => ro.disconnect()
  }, [])

  useEffect(() => {
    if (containerWidth <= 0 || isSidebarResizingRef.current) return
    setMountedSidebarPx((prev) =>
      prev === null || prev !== sidebarWidthPx ? sidebarWidthPx : prev,
    )
  }, [containerWidth, sidebarWidthPx])

  const panelLayout = useMemo(() => {
    if (mountedSidebarPx === null || containerWidth <= 0) return null
    return computeTwoPanelHorizontalLayout({
      containerWidth,
      firstPx: mountedSidebarPx,
      firstMinPx: TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX,
      firstMaxPx: TABLE_VIEW_SIDEBAR_MAX_WIDTH_PX,
      secondMinPx: TABLE_VIEW_MAIN_MIN_WIDTH_PX,
    })
  }, [containerWidth, mountedSidebarPx])

  const persistTimerRef = useRef<number | null>(null)
  const lastPersistedPxRef = useRef(sidebarWidthPx)
  const latestSidebarPxRef = useRef(sidebarWidthPx)

  useEffect(() => {
    if (isSidebarResizingRef.current) return
    lastPersistedPxRef.current = sidebarWidthPx
    latestSidebarPxRef.current = sidebarWidthPx
  }, [sidebarWidthPx])

  const handleLayout = useCallback(
    (sizes: number[]) => {
      const percent = sizes[0]
      if (typeof percent !== 'number' || !Number.isFinite(percent)) return
      if (containerWidth <= 0) return
      const layout = computeTwoPanelHorizontalLayout({
        containerWidth,
        firstPx: (percent / 100) * containerWidth,
        firstMinPx: TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX,
        firstMaxPx: TABLE_VIEW_SIDEBAR_MAX_WIDTH_PX,
        secondMinPx: TABLE_VIEW_MAIN_MIN_WIDTH_PX,
      })
      latestSidebarPxRef.current = layout.firstPx
      if (!isSidebarResizingRef.current) return
      const nextPx = layout.firstPx
      if (Math.abs(nextPx - lastPersistedPxRef.current) < 2) return
      if (persistTimerRef.current !== null) {
        window.clearTimeout(persistTimerRef.current)
      }
      persistTimerRef.current = window.setTimeout(() => {
        persistTimerRef.current = null
        lastPersistedPxRef.current = nextPx
        persistSidebarWidthPx(nextPx)
      }, PERSIST_DEBOUNCE_MS)
    },
    [containerWidth, persistSidebarWidthPx],
  )

  const finishSidebarResize = useCallback(
    (e?: PointerEvent<HTMLDivElement>) => {
      if (!isSidebarResizingRef.current) return
      isSidebarResizingRef.current = false
      if (e?.currentTarget.hasPointerCapture(e.pointerId)) {
        try {
          e.currentTarget.releasePointerCapture(e.pointerId)
        } catch {
          /* already released */
        }
      }
      if (persistTimerRef.current !== null) {
        window.clearTimeout(persistTimerRef.current)
        persistTimerRef.current = null
      }
      const nextPx = latestSidebarPxRef.current
      lastPersistedPxRef.current = nextPx
      setMountedSidebarPx(nextPx)
      persistSidebarWidthPx(nextPx)
    },
    [persistSidebarWidthPx],
  )

  const handleSidebarResizePointerDown = useCallback(
    (e: PointerEvent<HTMLDivElement>) => {
      isSidebarResizingRef.current = true
      e.currentTarget.setPointerCapture(e.pointerId)
    },
    [],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        window.clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  const shellClassName = cn(
    'flex h-full min-h-0 min-w-0 flex-1 flex-col',
    className,
  )

  if (!panelLayout) {
    return (
      <div ref={containerRef} className={shellClassName}>
        <div className="flex h-full min-h-0 min-w-0 flex-1">
          <div
            className="flex h-full min-h-0 shrink-0 flex-col overflow-hidden border-r border-border bg-background"
            style={{ width: sidebarWidthPx }}
          >
            {sidebar}
          </div>
          <div className="min-h-0 min-w-0 flex-1">{children}</div>
        </div>
      </div>
    )
  }

  return (
    <div ref={containerRef} className={shellClassName}>
      <ResizablePanelGroup
        key={`${sidebarWidthScope}-${mountedSidebarPx}`}
        direction="horizontal"
        className="h-full min-h-0 min-w-0 flex-1"
        onLayout={handleLayout}
      >
        <ResizablePanel
          defaultSize={panelLayout.firstPercent}
          minSize={panelLayout.firstMinPercent}
          maxSize={panelLayout.firstMaxPercent}
          className="min-w-0"
        >
          <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden border-r border-border bg-background">
            {sidebar}
          </div>
        </ResizablePanel>
        <ResizableHandle
          className={HANDLE_CLASS}
          onPointerDown={handleSidebarResizePointerDown}
          onPointerUp={finishSidebarResize}
          onPointerCancel={finishSidebarResize}
        />
        <ResizablePanel
          defaultSize={panelLayout.secondPercent}
          minSize={panelLayout.secondMinPercent}
          className="min-w-0"
        >
          {children}
        </ResizablePanel>
      </ResizablePanelGroup>
    </div>
  )
}
