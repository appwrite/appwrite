import type { ReactNode } from 'react'
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
import { useDatabasesSidebarWidth } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'

/** Above rows grid stickies (`z-20`–`z-40` in View.tsx), below overlays (`z-50+`). */
const HANDLE_CLASS = cn(
  'relative z-[45] w-[0.5px] bg-border',
  'before:pointer-events-none before:absolute before:inset-y-0 before:left-1/2 before:w-2 before:-translate-x-1/2 before:bg-border before:opacity-0 before:transition-opacity',
  'hover:before:opacity-100 data-[resize-handle-state=drag]:before:opacity-100',
  'after:w-2 after:left-1/2 after:-translate-x-1/2',
)

/** Matches legacy fixed sidebar `w-56` (14rem). */
const SIDEBAR_MIN_WIDTH_PX = 224

/** Minimum width for the main (rows / header / content) pane. */
const MAIN_MIN_WIDTH_PX = 360

/** Sidebar cannot exceed this width (converted to % of the measured group). */
const SIDEBAR_MAX_WIDTH_PX = 480

/** Also cap sidebar max at this % (matches previous `maxSize={40}` on smaller groups). */
const SIDEBAR_MAX_PERCENT_CAP = 40

/** Debounce window for persisting the sidebar width to user prefs. */
const PERSIST_DEBOUNCE_MS = 250

function pxToMinPercent(
  px: number,
  containerWidth: number,
  fallbackPercent: number,
): number {
  if (containerWidth <= 0) return fallbackPercent
  return Math.min(100, (px / containerWidth) * 100)
}

type TableViewResizableLayoutProps = {
  sidebar: ReactNode
  children: ReactNode
  className?: string
}

/**
 * Left sub-navigation + main area with a draggable split, matching the
 * functions code editor and shared `ResizableHandle` styling.
 *
 * Minimum widths are defined in px; the library only accepts %, so we measure
 * the group and convert (see react-resizable-panels constraint notes).
 *
 * The sidebar width is persisted in user (account) preferences as a single
 * shared setting (`console.databases.sidebarWidth`) so it follows the user
 * across devices and is the same across all databases and tables.
 */
export function TableViewResizableLayout({
  sidebar,
  children,
  className,
}: TableViewResizableLayoutProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const [containerWidth, setContainerWidth] = useState(0)
  const { account } = useAuth()
  const { widthPercent, setWidthPercent } = useDatabasesSidebarWidth(
    account as { prefs?: Record<string, unknown> } | undefined,
  )

  // Capture the initial persisted width once so the panel group is created
  // with stable `defaultSize` props (changing them later would otherwise
  // cause the library to reset the layout while the user is dragging).
  const initialWidthPercentRef = useRef(widthPercent)
  const initialSidebarPercent = initialWidthPercentRef.current
  const initialMainPercent = 100 - initialSidebarPercent

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

  const { sidebarMinPercent, sidebarMaxPercent, mainMinPercent } = useMemo(() => {
    const w = containerWidth
    const sidebarMin = pxToMinPercent(SIDEBAR_MIN_WIDTH_PX, w, 14)
    const sidebarMaxFromPx =
      w <= 0 ? SIDEBAR_MAX_PERCENT_CAP : (SIDEBAR_MAX_WIDTH_PX / w) * 100
    const sidebarMaxUncapped = Math.min(SIDEBAR_MAX_PERCENT_CAP, sidebarMaxFromPx)
    const sidebarMax = Math.max(sidebarMin, sidebarMaxUncapped)
    const mainFromPx = pxToMinPercent(MAIN_MIN_WIDTH_PX, w, 45)
    const mainMin =
      w <= 0
        ? 45
        : Math.min(mainFromPx, Math.max(0, 100 - sidebarMin))
    return {
      sidebarMinPercent: sidebarMin,
      sidebarMaxPercent: sidebarMax,
      mainMinPercent: mainMin,
    }
  }, [containerWidth])

  // Persist the sidebar width to user prefs after the user stops resizing.
  const persistTimerRef = useRef<number | null>(null)
  const lastPersistedRef = useRef(widthPercent)
  const handleLayout = useCallback(
    (sizes: number[]) => {
      const next = sizes[0]
      if (typeof next !== 'number' || !Number.isFinite(next)) return
      // Avoid spamming the API if the size hasn't meaningfully changed.
      if (Math.abs(next - lastPersistedRef.current) < 0.1) return
      if (persistTimerRef.current !== null) {
        window.clearTimeout(persistTimerRef.current)
      }
      persistTimerRef.current = window.setTimeout(() => {
        persistTimerRef.current = null
        lastPersistedRef.current = next
        setWidthPercent(next)
      }, PERSIST_DEBOUNCE_MS)
    },
    [setWidthPercent],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        window.clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  return (
    <div
      ref={containerRef}
      className={cn(
        'flex h-full min-h-0 min-w-0 flex-1 flex-col',
        className,
      )}
    >
      <ResizablePanelGroup
        direction="horizontal"
        className="h-full min-h-0 min-w-0 flex-1"
        onLayout={handleLayout}
      >
        <ResizablePanel
          defaultSize={initialSidebarPercent}
          minSize={sidebarMinPercent}
          maxSize={sidebarMaxPercent}
          className="min-w-0"
        >
          <div className="flex h-full min-h-0 min-w-0 flex-col overflow-hidden border-r border-border bg-background">
            {sidebar}
          </div>
        </ResizablePanel>
        <ResizableHandle className={HANDLE_CLASS} />
        <ResizablePanel
          defaultSize={initialMainPercent}
          minSize={mainMinPercent}
          className="min-w-0"
        >
          {children}
        </ResizablePanel>
      </ResizablePanelGroup>
    </div>
  )
}
