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
import { useCliShellSessionsSidebarWidth } from '@/lib/react-query/hooks/auth'
import { parseCliShellSessionsSidebarWidthPx } from '@/lib/user-prefs-keys'
import {
  clampCliShellSessionsSidebarWidthPx,
  CLI_SHELL_SESSIONS_SIDEBAR_DEFAULT_WIDTH_PX,
  CLI_SHELL_SESSIONS_SIDEBAR_MAX_WIDTH_PX,
  CLI_SHELL_SESSIONS_SIDEBAR_MIN_WIDTH_PX,
  CLI_SHELL_SESSIONS_STRIP_MAX_WIDTH_PX,
  CLI_SHELL_TERMINAL_MAIN_MIN_WIDTH_PX,
  computeTwoPanelHorizontalLayout,
  syncPanelGroupFirstPanePx,
} from '@/lib/resizable-layout'
import { cn } from '@/lib/utils'
import { CliTerminalLayoutProvider } from './CliTerminalLayoutContext'

const HANDLE_CLASS = cn(
  'relative z-[45] w-[0.5px] bg-border',
  'before:pointer-events-none before:absolute before:inset-y-0 before:start-1/2 before:w-2 before:-translate-x-1/2 before:bg-border before:opacity-0 before:transition-opacity',
  'hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
  'after:w-2 after:start-1/2 after:-translate-x-1/2',
)

const PERSIST_DEBOUNCE_MS = 250

type CliTerminalResizableLayoutProps = {
  main: ReactNode
  sidebar: ReactNode
  onSidebarResizingChange?: (isResizing: boolean) => void
}

export function CliTerminalResizableLayout({
  main,
  sidebar,
  onSidebarResizingChange,
}: CliTerminalResizableLayoutProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const firstPanelRef = useRef<ImperativePanelHandle>(null)
  const prevContainerWidthRef = useRef(0)
  const [containerWidth, setContainerWidth] = useState(0)
  const { account } = useAuth()
  const accountPrefs = account as { prefs?: Record<string, unknown> } | undefined
  const { persistSidebarWidthPx } = useCliShellSessionsSidebarWidth(accountPrefs)

  const sidebarWidthPx = useMemo(
    () =>
      clampCliShellSessionsSidebarWidthPx(
        parseCliShellSessionsSidebarWidthPx(accountPrefs?.prefs) ??
          CLI_SHELL_SESSIONS_SIDEBAR_DEFAULT_WIDTH_PX,
      ),
    [accountPrefs?.prefs],
  )

  const [mountedSidebarPx, setMountedSidebarPx] = useState<number | null>(null)
  const isSidebarResizingRef = useRef(false)
  const effectiveSidebarPx = mountedSidebarPx ?? sidebarWidthPx

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

  const isStripLayout =
    containerWidth > 0 && containerWidth < CLI_SHELL_SESSIONS_STRIP_MAX_WIDTH_PX

  useEffect(() => {
    if (typeof window === 'undefined' || containerWidth <= 0) return
    window.dispatchEvent(new Event('resize'))
  }, [containerWidth, isStripLayout])

  const panelLayout = useMemo(() => {
    if (isStripLayout || containerWidth <= 0) return null
    return computeTwoPanelHorizontalLayout({
      containerWidth,
      firstPx: containerWidth - effectiveSidebarPx,
      firstMinPx: Math.max(
        CLI_SHELL_TERMINAL_MAIN_MIN_WIDTH_PX,
        containerWidth - CLI_SHELL_SESSIONS_SIDEBAR_MAX_WIDTH_PX,
      ),
      firstMaxPx: containerWidth - CLI_SHELL_SESSIONS_SIDEBAR_MIN_WIDTH_PX,
      secondMinPx: CLI_SHELL_SESSIONS_SIDEBAR_MIN_WIDTH_PX,
    })
  }, [containerWidth, effectiveSidebarPx, isStripLayout])

  useLayoutEffect(() => {
    if (isStripLayout || containerWidth <= 0 || mountedSidebarPx === null) return
    if (isSidebarResizingRef.current) return

    const prevWidth = prevContainerWidthRef.current
    prevContainerWidthRef.current = containerWidth
    if (prevWidth <= 0 || prevWidth === containerWidth) return

    const mainPx = containerWidth - mountedSidebarPx
    const nextMainPx = syncPanelGroupFirstPanePx(
      firstPanelRef.current,
      containerWidth,
      mainPx,
      {
        firstMinPx: Math.max(
          CLI_SHELL_TERMINAL_MAIN_MIN_WIDTH_PX,
          containerWidth - CLI_SHELL_SESSIONS_SIDEBAR_MAX_WIDTH_PX,
        ),
        firstMaxPx: containerWidth - CLI_SHELL_SESSIONS_SIDEBAR_MIN_WIDTH_PX,
        secondMinPx: CLI_SHELL_SESSIONS_SIDEBAR_MIN_WIDTH_PX,
      },
    )
    const nextSidebarPx = clampCliShellSessionsSidebarWidthPx(
      containerWidth - nextMainPx,
    )
    if (nextSidebarPx !== mountedSidebarPx) {
      setMountedSidebarPx(nextSidebarPx)
    }
  }, [containerWidth, isStripLayout, mountedSidebarPx])

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
      const sidebarPercent = sizes[1]
      if (typeof sidebarPercent !== 'number' || !Number.isFinite(sidebarPercent)) {
        return
      }
      if (containerWidth <= 0) return
      const nextSidebarPx = clampCliShellSessionsSidebarWidthPx(
        (sidebarPercent / 100) * containerWidth,
      )
      latestSidebarPxRef.current = nextSidebarPx
      if (!isSidebarResizingRef.current) return
      if (Math.abs(nextSidebarPx - lastPersistedPxRef.current) < 2) return
      if (persistTimerRef.current !== null) {
        window.clearTimeout(persistTimerRef.current)
      }
      persistTimerRef.current = window.setTimeout(() => {
        persistTimerRef.current = null
        lastPersistedPxRef.current = nextSidebarPx
        persistSidebarWidthPx(nextSidebarPx)
      }, PERSIST_DEBOUNCE_MS)
    },
    [containerWidth, persistSidebarWidthPx],
  )

  const finishSidebarResize = useCallback(
    (e?: PointerEvent<HTMLDivElement>) => {
      if (!isSidebarResizingRef.current) return
      isSidebarResizingRef.current = false
      onSidebarResizingChange?.(false)
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
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new Event('resize'))
      }
    },
    [onSidebarResizingChange, persistSidebarWidthPx],
  )

  const handleSidebarResizePointerDown = useCallback(
    (e: PointerEvent<HTMLDivElement>) => {
      isSidebarResizingRef.current = true
      onSidebarResizingChange?.(true)
      e.currentTarget.setPointerCapture(e.pointerId)
    },
    [onSidebarResizingChange],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        window.clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  if (isStripLayout) {
    return (
      <CliTerminalLayoutProvider mode="strip">
        <div
          ref={containerRef}
          className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden"
        >
          <div className="shrink-0">{sidebar}</div>
          <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            {main}
          </div>
        </div>
      </CliTerminalLayoutProvider>
    )
  }

  if (!panelLayout) {
    return (
      <CliTerminalLayoutProvider mode="sidebar">
        <div
          ref={containerRef}
          className="flex h-full min-h-0 min-w-0 flex-1 overflow-hidden"
        >
          <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            {main}
          </div>
          <div
            className="flex min-h-0 shrink-0 flex-col overflow-hidden border-s border-border bg-muted/20"
            style={{ width: sidebarWidthPx }}
          >
            {sidebar}
          </div>
        </div>
      </CliTerminalLayoutProvider>
    )
  }

  return (
    <CliTerminalLayoutProvider mode="sidebar">
      <div
        ref={containerRef}
        className="flex h-full min-h-0 min-w-0 flex-1 overflow-hidden"
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
          <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden">
            {main}
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
          <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden border-s border-border bg-muted/20">
            {sidebar}
          </div>
        </ResizablePanel>
        </ResizablePanelGroup>
      </div>
    </CliTerminalLayoutProvider>
  )
}
