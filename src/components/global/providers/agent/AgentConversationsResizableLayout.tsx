import type { ReactNode } from 'react'
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
import { useAIChatConversationsWidth } from '@/lib/react-query/hooks/auth'
import { parseAIChatConversationsWidthPx } from '@/lib/user-prefs-keys'
import {
  AI_CHAT_CONVERSATIONS_SIDEBAR_DEFAULT_WIDTH_PX,
  AI_CHAT_CONVERSATIONS_SIDEBAR_MAX_WIDTH_PX,
  AI_CHAT_CONVERSATIONS_SIDEBAR_MIN_WIDTH_PX,
  AI_CHAT_MAIN_MIN_WIDTH_PX,
  clampAIChatConversationsSidebarWidthPx,
  computeTwoPanelHorizontalLayout,
  effectivePanelGroupWidthPx,
  syncPanelGroupFirstPanePx,
} from '@/lib/resizable-layout'
import { verticalPanelResizeHandleClass } from '@/lib/layout/horizontal-resize'
import { cn } from '@/lib/utils'

const HANDLE_CLASS = verticalPanelResizeHandleClass('z-[45]')

type AgentConversationsResizableLayoutProps = {
  sidebar: ReactNode
  children: ReactNode
  className?: string
}

/**
 * Conversations list + chat area with a draggable split, matching
 * `TableViewResizableLayout` / shared `ResizableHandle` styling.
 */
export function AgentConversationsResizableLayout({
  sidebar,
  children,
  className,
}: AgentConversationsResizableLayoutProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const firstPanelRef = useRef<ImperativePanelHandle>(null)
  const prevContainerWidthRef = useRef(0)
  const [containerWidth, setContainerWidth] = useState(0)
  const { account } = useAuth()
  const accountPrefs = account as { prefs?: Record<string, unknown> } | undefined
  const { persistSidebarWidthPx } = useAIChatConversationsWidth(accountPrefs)

  const sidebarWidthPx = useMemo(
    () =>
      clampAIChatConversationsSidebarWidthPx(
        parseAIChatConversationsWidthPx(accountPrefs?.prefs) ??
          AI_CHAT_CONVERSATIONS_SIDEBAR_DEFAULT_WIDTH_PX,
      ),
    [accountPrefs?.prefs],
  )

  const [mountedSidebarPx, setMountedSidebarPx] = useState(sidebarWidthPx)
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
    if (isSidebarResizingRef.current) return
    setMountedSidebarPx((prev) =>
      prev !== sidebarWidthPx ? sidebarWidthPx : prev,
    )
  }, [sidebarWidthPx])

  const panelLayout = useMemo(
    () =>
      computeTwoPanelHorizontalLayout({
        containerWidth: effectivePanelGroupWidthPx(containerWidth),
        firstPx: mountedSidebarPx,
        firstMinPx: AI_CHAT_CONVERSATIONS_SIDEBAR_MIN_WIDTH_PX,
        firstMaxPx: AI_CHAT_CONVERSATIONS_SIDEBAR_MAX_WIDTH_PX,
        secondMinPx: AI_CHAT_MAIN_MIN_WIDTH_PX,
      }),
    [containerWidth, mountedSidebarPx],
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
        firstMinPx: AI_CHAT_CONVERSATIONS_SIDEBAR_MIN_WIDTH_PX,
        firstMaxPx: AI_CHAT_CONVERSATIONS_SIDEBAR_MAX_WIDTH_PX,
        secondMinPx: AI_CHAT_MAIN_MIN_WIDTH_PX,
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
      const width = effectivePanelGroupWidthPx(containerWidth)
      const layout = computeTwoPanelHorizontalLayout({
        containerWidth: width,
        firstPx: (percent / 100) * width,
        firstMinPx: AI_CHAT_CONVERSATIONS_SIDEBAR_MIN_WIDTH_PX,
        firstMaxPx: AI_CHAT_CONVERSATIONS_SIDEBAR_MAX_WIDTH_PX,
        secondMinPx: AI_CHAT_MAIN_MIN_WIDTH_PX,
      })
      latestSidebarPxRef.current = layout.firstPx
    },
    [containerWidth],
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
