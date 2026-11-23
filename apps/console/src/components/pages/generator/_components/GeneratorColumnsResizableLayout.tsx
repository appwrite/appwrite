import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import type { ImperativePanelHandle } from 'react-resizable-panels'
import {
  ResizableHandle,
  ResizablePanel,
  ResizablePanelGroup,
} from '@/components/ui/resizable'
import {
  COVER_GENERATOR_COLUMNS_MAX,
  COVER_GENERATOR_COLUMNS_MIN,
  normalizeCoverGeneratorColumnsLayout,
} from '@/lib/resizable-layout'

const PERSIST_DEBOUNCE_MS = 250

type GeneratorColumnsResizableLayoutProps = {
  layout: number[]
  persistLayout: (layout: number[]) => void
  leftOpen: boolean
  rightOpen: boolean
  onLeftOpenChange: (open: boolean) => void
  onRightOpenChange: (open: boolean) => void
  handleClassName: string
  className?: string
  templates: ReactNode
  canvas: ReactNode
  properties: ReactNode
}

function applyPanelOpenState(
  panel: ImperativePanelHandle | null,
  open: boolean,
) {
  if (!panel) return

  try {
    if (open) panel.expand()
    else panel.collapse()
  } catch {
    // Panel group has not computed sizes yet.
  }
}

export function GeneratorColumnsResizableLayout({
  layout,
  persistLayout,
  leftOpen,
  rightOpen,
  onLeftOpenChange,
  onRightOpenChange,
  handleClassName,
  className,
  templates,
  canvas,
  properties,
}: GeneratorColumnsResizableLayoutProps) {
  const leftPanelRef = useRef<ImperativePanelHandle>(null)
  const rightPanelRef = useRef<ImperativePanelHandle>(null)
  const [mountedLayout, setMountedLayout] = useState(() =>
    normalizeCoverGeneratorColumnsLayout(layout),
  )
  const isResizingRef = useRef(false)
  const persistTimerRef = useRef<number | null>(null)
  const lastPersistedRef = useRef(mountedLayout)
  const latestLayoutRef = useRef(mountedLayout)

  useEffect(() => {
    if (isResizingRef.current) return
    const normalized = normalizeCoverGeneratorColumnsLayout(layout)
    lastPersistedRef.current = normalized
    latestLayoutRef.current = normalized
    setMountedLayout((prev) =>
      prev.join(',') === normalized.join(',') ? prev : normalized,
    )
  }, [layout])

  useEffect(() => {
    let frameId = 0

    const syncPanels = () => {
      applyPanelOpenState(leftPanelRef.current, leftOpen)
      applyPanelOpenState(rightPanelRef.current, rightOpen)
    }

    frameId = window.requestAnimationFrame(() => {
      frameId = window.requestAnimationFrame(syncPanels)
    })

    return () => {
      if (frameId !== 0) window.cancelAnimationFrame(frameId)
    }
  }, [leftOpen, rightOpen])

  const handleLayout = useCallback(
    (sizes: number[]) => {
      const normalized = normalizeCoverGeneratorColumnsLayout(sizes)
      latestLayoutRef.current = normalized
      if (!isResizingRef.current) return
      if (
        normalized.every(
          (size, index) =>
            Math.abs(size - lastPersistedRef.current[index]!) < 0.5,
        )
      ) {
        return
      }
      if (persistTimerRef.current !== null) {
        window.clearTimeout(persistTimerRef.current)
      }
      persistTimerRef.current = window.setTimeout(() => {
        persistTimerRef.current = null
        lastPersistedRef.current = normalized
        persistLayout(normalized)
      }, PERSIST_DEBOUNCE_MS)
    },
    [persistLayout],
  )

  const handleDragging = useCallback(
    (isDragging: boolean) => {
      if (isDragging) {
        isResizingRef.current = true
        return
      }
      isResizingRef.current = false
      if (persistTimerRef.current !== null) {
        window.clearTimeout(persistTimerRef.current)
        persistTimerRef.current = null
      }
      const next = latestLayoutRef.current
      lastPersistedRef.current = next
      setMountedLayout(next)
      persistLayout(next)
    },
    [persistLayout],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        window.clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  const layoutKey = mountedLayout.map((size) => size.toFixed(1)).join('-')

  return (
    <ResizablePanelGroup
      key={layoutKey}
      direction="horizontal"
      className={className}
      onLayout={handleLayout}
    >
      <ResizablePanel
        ref={leftPanelRef}
        defaultSize={leftOpen ? mountedLayout[0] : 0}
        minSize={COVER_GENERATOR_COLUMNS_MIN[0]}
        maxSize={COVER_GENERATOR_COLUMNS_MAX[0]}
        collapsible
        collapsedSize={0}
        onCollapse={() => onLeftOpenChange(false)}
        onExpand={() => onLeftOpenChange(true)}
        className="min-h-0 min-w-0 overflow-hidden"
      >
        {templates}
      </ResizablePanel>

      <ResizableHandle className={handleClassName} onDragging={handleDragging} />

      <ResizablePanel
        defaultSize={mountedLayout[1]}
        minSize={COVER_GENERATOR_COLUMNS_MIN[1]}
        className="min-h-0 min-w-0 overflow-hidden"
      >
        {canvas}
      </ResizablePanel>

      <ResizableHandle className={handleClassName} onDragging={handleDragging} />

      <ResizablePanel
        ref={rightPanelRef}
        defaultSize={rightOpen ? mountedLayout[2] : 0}
        minSize={COVER_GENERATOR_COLUMNS_MIN[2]}
        maxSize={COVER_GENERATOR_COLUMNS_MAX[2]}
        collapsible
        collapsedSize={0}
        onCollapse={() => onRightOpenChange(false)}
        onExpand={() => onRightOpenChange(true)}
        className="min-h-0 min-w-0 overflow-hidden"
      >
        {properties}
      </ResizablePanel>
    </ResizablePanelGroup>
  )
}
