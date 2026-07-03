import type { PointerEvent, ReactNode } from 'react'
import {
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
  syncPanelGroupFirstPanePx,
  TABLE_VIEW_MAIN_MIN_WIDTH_PX,
  TABLE_VIEW_SIDEBAR_DEFAULT_WIDTH_PX,
  TABLE_VIEW_SIDEBAR_MAX_WIDTH_PX,
  TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX,
} from '@/lib/resizable-layout'
import { verticalPanelResizeHandleClass } from '@/lib/layout/horizontal-resize'
import { cn } from '@/lib/utils'

/** Above rows grid stickies (`z-20`–`z-40` in View.tsx), below overlays (`z-50+`). */
const HANDLE_CLASS = verticalPanelResizeHandleClass('z-[45]')

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
  const firstPanelRef = useRef<ImperativePanelHandle>(null)
  const prevContainerWidthRef = useRef(0)
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

  useLayoutEffect(() => {
    if (mountedSidebarPx === null || containerWidth <= 0) return
    if (isSidebarResizingRef.current) return

    const prevWidth = prevContainerWidthRef.current
    prevContainerWidthRef.current = containerWidth
    if (prevWidth <= 0 || prevWidth === containerWidth) return

    const nextPx = syncPanelGroupFirstPanePx(
      firstPanelRef.current,
      containerWidth,
      mountedSidebarPx,
      {
        firstMinPx: TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX,
        firstMaxPx: TABLE_VIEW_SIDEBAR_MAX_WIDTH_PX,
        secondMinPx: TABLE_VIEW_MAIN_MIN_WIDTH_PX,
      },
    )
    if (nextPx !== mountedSidebarPx) {
      setMountedSidebarPx(nextPx)
    }
  }, [containerWidth, mountedSidebarPx])

  const latestSidebarPxRef = useRef(sidebarWidthPx)

  useEffect(() => {
    if (isSidebarResizingRef.current) return
    latestSidebarPxRef.current = sidebarWidthPx
  }, [sidebarWidthPx])

  const handleLayout = useCallback(
    (sizes: number[]) => {
      if (!isSidebarResizingRef.current) return
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
    },
    [containerWidth],
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
      const nextPx = latestSidebarPxRef.current
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

  const shellClassName = cn(
    'flex h-full min-h-0 min-w-0 flex-1 flex-col',
    className,
  )

  if (!panelLayout) {
    return (
      <div ref={containerRef} className={shellClassName}>
        <div className="flex h-full min-h-0 min-w-0 flex-1">
          <div
            className="flex h-full min-h-0 shrink-0 flex-col overflow-hidden border-e border-border bg-background"
            style={{ width: sidebarWidthPx }}
          >
            {sidebar}
          </div>
          <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            {children}
          </div>
        </div>
      </div>
    )
  }

  return (
    <div ref={containerRef} className={shellClassName}>
      <ResizablePanelGroup
        direction="horizontal"
        className="h-full min-h-0 min-w-0 flex-1"
        onLayout={handleLayout}
      >
        <ResizablePanel
          ref={firstPanelRef}
          defaultSize={panelLayout.firstPercent}
          minSize={panelLayout.firstMinPercent}
          maxSize={panelLayout.firstMaxPercent}
          className="min-w-0"
        >
          <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden border-e border-border bg-background">
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
          <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden">
            {children}
          </div>
        </ResizablePanel>
      </ResizablePanelGroup>
    </div>
  )
}
