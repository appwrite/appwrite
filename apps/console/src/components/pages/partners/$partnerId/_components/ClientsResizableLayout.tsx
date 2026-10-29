import type { PointerEvent as ReactPointerEvent, ReactNode } from 'react'
import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import type { ImperativePanelHandle } from 'react-resizable-panels'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  ResizableHandle,
  ResizablePanel,
  ResizablePanelGroup,
} from '@/components/ui/resizable'
import {
  horizontalResizeDeltaPx,
  setBodyResizeDragActive,
  verticalPanelResizeHandleClass,
} from '@/lib/layout/horizontal-resize'
import {
  usePartnerClientNavColumnLayout,
  usePartnerClientsColumnLayout,
} from '@/lib/react-query/hooks'
import {
  clampPartnerClientNavColumnWidthPx,
  clampPartnerClientsColumnWidthPx,
  computeTwoPanelHorizontalLayout,
  effectivePanelGroupWidthPx,
  PARTNER_CLIENT_DETAIL_MIN_WIDTH_PX,
  PARTNER_CLIENT_NAV_COLUMN_MAX_WIDTH_PX,
  PARTNER_CLIENT_NAV_COLUMN_MIN_WIDTH_PX,
  PARTNER_CLIENTS_COLUMN_COLLAPSED_WIDTH_PX,
  PARTNER_CLIENTS_COLUMN_MAX_WIDTH_PX,
  PARTNER_CLIENTS_COLUMN_MIN_WIDTH_PX,
  PARTNER_CLIENTS_MAIN_MIN_WIDTH_PX,
  syncPanelGroupFirstPanePx,
} from '@/lib/resizable-layout'
import { cn } from '@/lib/utils'

const HANDLE_CLASS = verticalPanelResizeHandleClass('z-[45]')

function measuredPanelGroupWidthPx(width: number): number {
  if (!Number.isFinite(width) || width <= 0) return 0
  return Math.round(width)
}

type PartnerSplitLayoutProps = {
  sidebar: ReactNode
  children: ReactNode
  className?: string
  sidebarWidthPx: number
  persistSidebarWidthPx: (px: number) => void
  clampWidthPx: (px: number) => number
  firstMinPx: number
  firstMaxPx: number
  secondMinPx: number
}

function PartnerSplitLayout({
  sidebar,
  children,
  className,
  sidebarWidthPx,
  persistSidebarWidthPx,
  clampWidthPx,
  firstMinPx,
  firstMaxPx,
  secondMinPx,
}: PartnerSplitLayoutProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const firstPanelRef = useRef<ImperativePanelHandle>(null)
  const prevContainerWidthRef = useRef(0)
  const [containerWidth, setContainerWidth] = useState(0)
  const persistedSidebarPx = clampWidthPx(sidebarWidthPx)
  const [mountedSidebarPx, setMountedSidebarPx] = useState(persistedSidebarPx)
  const isSidebarResizingRef = useRef(false)

  useLayoutEffect(() => {
    const el = containerRef.current
    if (!el) return
    const applyWidth = (width: number) => {
      const next = measuredPanelGroupWidthPx(width)
      if (next <= 0) return
      setContainerWidth((prev) => (prev === next ? prev : next))
    }
    const ro = new ResizeObserver((entries) => {
      applyWidth(entries[0]?.contentRect.width ?? 0)
    })
    ro.observe(el)
    applyWidth(el.getBoundingClientRect().width)
    return () => ro.disconnect()
  }, [])

  useEffect(() => {
    if (isSidebarResizingRef.current) return
    setMountedSidebarPx((prev) =>
      prev !== persistedSidebarPx ? persistedSidebarPx : prev,
    )
  }, [persistedSidebarPx])

  const panelLayout = useMemo(
    () =>
      computeTwoPanelHorizontalLayout({
        containerWidth: effectivePanelGroupWidthPx(containerWidth),
        firstPx: mountedSidebarPx,
        firstMinPx,
        firstMaxPx,
        secondMinPx,
      }),
    [containerWidth, firstMaxPx, firstMinPx, mountedSidebarPx, secondMinPx],
  )

  useLayoutEffect(() => {
    if (containerWidth <= 0) return
    if (isSidebarResizingRef.current) return

    const prevWidth = prevContainerWidthRef.current
    prevContainerWidthRef.current = containerWidth
    if (prevWidth === containerWidth) return

    const nextPx = syncPanelGroupFirstPanePx(
      firstPanelRef.current,
      containerWidth,
      mountedSidebarPx,
      {
        firstMinPx,
        firstMaxPx,
        secondMinPx,
      },
    )
    if (nextPx !== mountedSidebarPx) {
      setMountedSidebarPx(nextPx)
    }
  }, [
    containerWidth,
    firstMaxPx,
    firstMinPx,
    mountedSidebarPx,
    secondMinPx,
  ])

  const latestSidebarPxRef = useRef(persistedSidebarPx)

  useEffect(() => {
    if (isSidebarResizingRef.current) return
    latestSidebarPxRef.current = persistedSidebarPx
  }, [persistedSidebarPx])

  const handleLayout = useCallback(
    (sizes: number[]) => {
      if (!isSidebarResizingRef.current) return
      const percent = sizes[0]
      if (typeof percent !== 'number' || !Number.isFinite(percent)) return
      const width = effectivePanelGroupWidthPx(containerWidth)
      const layout = computeTwoPanelHorizontalLayout({
        containerWidth: width,
        firstPx: (percent / 100) * width,
        firstMinPx,
        firstMaxPx,
        secondMinPx,
      })
      latestSidebarPxRef.current = layout.firstPx
    },
    [containerWidth, firstMaxPx, firstMinPx, secondMinPx],
  )

  const handleSidebarDragging = useCallback(
    (isDragging: boolean) => {
      if (isDragging) {
        isSidebarResizingRef.current = true
        return
      }
      if (!isSidebarResizingRef.current) return
      isSidebarResizingRef.current = false
      persistSidebarWidthPx(latestSidebarPxRef.current)
    },
    [persistSidebarWidthPx],
  )

  return (
    <div
      ref={containerRef}
      className={cn('flex h-full min-h-0 min-w-0 flex-1 flex-col', className)}
    >
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
          onDragging={handleSidebarDragging}
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

type SplitLayoutProps = {
  sidebar: ReactNode
  children: ReactNode
  className?: string
}

/**
 * Clients list + client workspace with a draggable split, matching
 * `TableViewResizableLayout` / shared `ResizableHandle` styling.
 */
export function ClientsResizableLayout({
  sidebar,
  children,
  className,
}: SplitLayoutProps) {
  const { account } = useAuth()
  const accountPrefs = account as { prefs?: Record<string, unknown> } | undefined
  const { widthPx: sidebarWidthPx, persistSidebarWidthPx } =
    usePartnerClientsColumnLayout(accountPrefs)

  return (
    <PartnerSplitLayout
      sidebar={sidebar}
      className={className}
      sidebarWidthPx={sidebarWidthPx}
      persistSidebarWidthPx={persistSidebarWidthPx}
      clampWidthPx={clampPartnerClientsColumnWidthPx}
      firstMinPx={PARTNER_CLIENTS_COLUMN_MIN_WIDTH_PX}
      firstMaxPx={PARTNER_CLIENTS_COLUMN_MAX_WIDTH_PX}
      secondMinPx={PARTNER_CLIENTS_MAIN_MIN_WIDTH_PX}
    >
      {children}
    </PartnerSplitLayout>
  )
}

const CLIENTS_RAIL_TRANSITION_MS = 220
const CLIENTS_RAIL_HANDLE_CLASS = verticalPanelResizeHandleClass(
  'absolute inset-y-0 end-0 z-[45] w-[0.5px]',
)

/**
 * Shared partner grid: the clients rail slides to width 0 on Workspace so
 * nav + detail stay on the same split and header controls do not jump.
 */
export function PartnerClientsRailLayout({
  visible,
  collapsed,
  sidebar,
  children,
  className,
}: SplitLayoutProps & {
  visible: boolean
  collapsed: boolean
}) {
  const { account } = useAuth()
  const accountPrefs = account as { prefs?: Record<string, unknown> } | undefined
  const { widthPx, persistSidebarWidthPx } =
    usePartnerClientsColumnLayout(accountPrefs)
  const openWidthPx = collapsed
    ? PARTNER_CLIENTS_COLUMN_COLLAPSED_WIDTH_PX
    : clampPartnerClientsColumnWidthPx(widthPx)
  const [dragWidthPx, setDragWidthPx] = useState<number | null>(null)
  const [transitionsReady, setTransitionsReady] = useState(false)
  const dragWidthRef = useRef<number | null>(null)
  const displayedOpenWidthPx = dragWidthPx ?? openWidthPx
  const railWidthPx = visible ? displayedOpenWidthPx : 0
  const canResize = visible && !collapsed
  const reduceMotion =
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches

  useEffect(() => {
    const frame = window.requestAnimationFrame(() => setTransitionsReady(true))
    return () => window.cancelAnimationFrame(frame)
  }, [])

  useEffect(() => {
    if (visible) return
    dragWidthRef.current = null
    setDragWidthPx(null)
    setBodyResizeDragActive(false)
  }, [visible])

  const onResizePointerDown = useCallback(
    (event: ReactPointerEvent<HTMLButtonElement>) => {
      if (event.button !== 0 || collapsed) return
      event.preventDefault()
      const startX = event.clientX
      const startWidth = displayedOpenWidthPx
      setBodyResizeDragActive(true)

      const onMove = (moveEvent: PointerEvent) => {
        const next = clampPartnerClientsColumnWidthPx(
          startWidth + horizontalResizeDeltaPx(startX, moveEvent.clientX),
        )
        dragWidthRef.current = next
        setDragWidthPx(next)
      }
      const onUp = () => {
        window.removeEventListener('pointermove', onMove)
        window.removeEventListener('pointerup', onUp)
        setBodyResizeDragActive(false)
        const next = dragWidthRef.current
        dragWidthRef.current = null
        setDragWidthPx(null)
        if (next != null) persistSidebarWidthPx(next)
      }

      window.addEventListener('pointermove', onMove)
      window.addEventListener('pointerup', onUp)
    },
    [collapsed, displayedOpenWidthPx, persistSidebarWidthPx],
  )

  return (
    <div
      className={cn('flex h-full min-h-0 min-w-0 flex-1', className)}
    >
      <div
        className={cn(
          'relative h-full min-h-0 shrink-0',
          !visible && 'pointer-events-none',
        )}
        style={{
          width: railWidthPx,
          transition:
            transitionsReady && dragWidthPx == null && !reduceMotion
              ? `width ${CLIENTS_RAIL_TRANSITION_MS}ms cubic-bezier(0.2, 0, 0, 1)`
              : 'none',
        }}
        aria-hidden={!visible}
      >
        <div className="h-full min-h-0 overflow-hidden">
          <div
            className="h-full min-h-0 border-e border-border bg-background"
            style={{ width: displayedOpenWidthPx }}
          >
            {sidebar}
          </div>
        </div>
        {canResize ? (
          <button
            type="button"
            className={cn(CLIENTS_RAIL_HANDLE_CLASS, 'cursor-col-resize touch-none')}
            aria-label="Resize clients list"
            onPointerDown={onResizePointerDown}
          />
        ) : null}
      </div>
      <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
        {children}
      </div>
    </div>
  )
}

/**
 * Section menu + detail pane with the same draggable split as the
 * clients list column (client Resources/Plan/Organization, or Workspace
 * Resources: projects, domains, apps).
 */
export function ClientNavResizableLayout({
  sidebar,
  children,
  className,
}: SplitLayoutProps) {
  const { account } = useAuth()
  const accountPrefs = account as { prefs?: Record<string, unknown> } | undefined
  const { widthPx: sidebarWidthPx, persistSidebarWidthPx } =
    usePartnerClientNavColumnLayout(accountPrefs)

  return (
    <PartnerSplitLayout
      sidebar={sidebar}
      className={className}
      sidebarWidthPx={sidebarWidthPx}
      persistSidebarWidthPx={persistSidebarWidthPx}
      clampWidthPx={clampPartnerClientNavColumnWidthPx}
      firstMinPx={PARTNER_CLIENT_NAV_COLUMN_MIN_WIDTH_PX}
      firstMaxPx={PARTNER_CLIENT_NAV_COLUMN_MAX_WIDTH_PX}
      secondMinPx={PARTNER_CLIENT_DETAIL_MIN_WIDTH_PX}
    >
      {children}
    </PartnerSplitLayout>
  )
}
