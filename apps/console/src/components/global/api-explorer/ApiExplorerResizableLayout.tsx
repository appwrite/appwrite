import {
  Fragment,
  useCallback,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import {
  ResizableHandle,
  ResizablePanel,
  ResizablePanelGroup,
} from '@/components/ui/resizable'
import {
  API_EXPLORER_COLUMNS_MAX,
  API_EXPLORER_COLUMNS_MIN,
  API_EXPLORER_RESPONSE_SPLIT_MAX,
  API_EXPLORER_RESPONSE_SPLIT_MIN,
  API_REFERENCE_COLUMNS_MAX,
  API_REFERENCE_COLUMNS_MIN,
  COVER_GENERATOR_COLUMNS_MAX,
  COVER_GENERATOR_COLUMNS_MIN,
  normalizeApiExplorerColumnsLayout,
  normalizeApiExplorerResponseSplitLayout,
  normalizeApiReferenceColumnsLayout,
  normalizeCoverGeneratorColumnsLayout,
  normalizeDiagramGeneratorPropertiesSplitLayout,
  DIAGRAM_GENERATOR_PROPERTIES_SPLIT_MIN,
  DIAGRAM_GENERATOR_PROPERTIES_SPLIT_MAX,
} from '@/lib/resizable-layout'

const PERSIST_DEBOUNCE_MS = 250

type PersistedResizablePanelGroupProps = {
  direction: 'horizontal' | 'vertical'
  layout: number[]
  persistLayout: (layout: number[]) => void
  normalizeLayout: (layout: number[]) => number[]
  mins: readonly number[]
  maxs: readonly number[]
  className?: string
  handleClassName: string
  panelClassName?: string
  children: ReactNode[]
}

function PersistedResizablePanelGroup({
  direction,
  layout,
  persistLayout,
  normalizeLayout,
  mins,
  maxs,
  className,
  handleClassName,
  panelClassName,
  children,
}: PersistedResizablePanelGroupProps) {
  const [mountedLayout, setMountedLayout] = useState(() =>
    normalizeLayout(layout),
  )
  const isResizingRef = useRef(false)
  const persistTimerRef = useRef<number | null>(null)
  const lastPersistedRef = useRef(mountedLayout)
  const latestLayoutRef = useRef(mountedLayout)

  useEffect(() => {
    if (isResizingRef.current) return
    const normalized = normalizeLayout(layout)
    lastPersistedRef.current = normalized
    latestLayoutRef.current = normalized
    setMountedLayout((prev) =>
      prev.join(',') === normalized.join(',') ? prev : normalized,
    )
  }, [layout, normalizeLayout])

  const handleLayout = useCallback(
    (sizes: number[]) => {
      const normalized = normalizeLayout(sizes)
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
    [normalizeLayout, persistLayout],
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
      direction={direction}
      className={className}
      onLayout={handleLayout}
    >
      {children.map((panel, index) => (
        <Fragment key={index}>
          {index > 0 ? (
            <ResizableHandle
              className={handleClassName}
              onDragging={handleDragging}
            />
          ) : null}
          <ResizablePanel
            defaultSize={mountedLayout[index]}
            minSize={mins[index]}
            maxSize={maxs[index]! < 100 ? maxs[index] : undefined}
            className={panelClassName}
          >
            {panel}
          </ResizablePanel>
        </Fragment>
      ))}
    </ResizablePanelGroup>
  )
}

type ExplorerColumnsLayoutProps = {
  layout: number[]
  persistLayout: (layout: number[]) => void
  handleClassName: string
  className?: string
  services: ReactNode
  methods: ReactNode
  request: ReactNode
}

export function ExplorerColumnsResizableLayout({
  layout,
  persistLayout,
  handleClassName,
  className,
  services,
  methods,
  request,
}: ExplorerColumnsLayoutProps) {
  return (
    <PersistedResizablePanelGroup
      direction="horizontal"
      layout={layout}
      persistLayout={persistLayout}
      normalizeLayout={normalizeApiExplorerColumnsLayout}
      mins={API_EXPLORER_COLUMNS_MIN}
      maxs={API_EXPLORER_COLUMNS_MAX}
      className={className}
      handleClassName={handleClassName}
      panelClassName="flex h-full min-h-0 min-w-0 flex-col overflow-hidden"
    >
      {[services, methods, request]}
    </PersistedResizablePanelGroup>
  )
}

type ExplorerResponseSplitLayoutProps = {
  layout: number[]
  persistLayout: (layout: number[]) => void
  handleClassName: string
  className?: string
  request: ReactNode
  response: ReactNode
}

export function ExplorerResponseSplitResizableLayout({
  layout,
  persistLayout,
  handleClassName,
  className,
  request,
  response,
}: ExplorerResponseSplitLayoutProps) {
  return (
    <PersistedResizablePanelGroup
      direction="vertical"
      layout={layout}
      persistLayout={persistLayout}
      normalizeLayout={normalizeApiExplorerResponseSplitLayout}
      mins={API_EXPLORER_RESPONSE_SPLIT_MIN}
      maxs={API_EXPLORER_RESPONSE_SPLIT_MAX}
      className={className}
      handleClassName={handleClassName}
      panelClassName="min-h-0 overflow-hidden"
    >
      {[request, response]}
    </PersistedResizablePanelGroup>
  )
}

type ReferenceColumnsLayoutProps = {
  layout: number[]
  persistLayout: (layout: number[]) => void
  handleClassName: string
  className?: string
  methods: ReactNode
  request: ReactNode
}

type CoverGeneratorColumnsLayoutProps = {
  layout: number[]
  persistLayout: (layout: number[]) => void
  handleClassName: string
  className?: string
  templates: ReactNode
  canvas: ReactNode
  properties: ReactNode
}

/** Docs API reference: methods list + request detail (explorer columns 2 and 3). */
export function ReferenceColumnsResizableLayout({
  layout,
  persistLayout,
  handleClassName,
  className,
  methods,
  request,
}: ReferenceColumnsLayoutProps) {
  return (
    <PersistedResizablePanelGroup
      direction="horizontal"
      layout={layout}
      persistLayout={persistLayout}
      normalizeLayout={normalizeApiReferenceColumnsLayout}
      mins={API_REFERENCE_COLUMNS_MIN}
      maxs={API_REFERENCE_COLUMNS_MAX}
      className={className}
      handleClassName={handleClassName}
      panelClassName="min-h-0 min-w-0 overflow-hidden"
    >
      {[methods, request]}
    </PersistedResizablePanelGroup>
  )
}

/** Cover generator: templates | canvas | properties. */
export function CoverGeneratorColumnsResizableLayout({
  layout,
  persistLayout,
  handleClassName,
  className,
  templates,
  canvas,
  properties,
}: CoverGeneratorColumnsLayoutProps) {
  return (
    <PersistedResizablePanelGroup
      direction="horizontal"
      layout={layout}
      persistLayout={persistLayout}
      normalizeLayout={normalizeCoverGeneratorColumnsLayout}
      mins={COVER_GENERATOR_COLUMNS_MIN}
      maxs={COVER_GENERATOR_COLUMNS_MAX}
      className={className}
      handleClassName={handleClassName}
      panelClassName="min-h-0 min-w-0 overflow-hidden"
    >
      {[templates, canvas, properties]}
    </PersistedResizablePanelGroup>
  )
}

type DiagramPropertiesSplitLayoutProps = {
  layout: number[]
  persistLayout: (layout: number[]) => void
  handleClassName: string
  className?: string
  properties: ReactNode
  layers: ReactNode
}

/** Diagram generator: properties | layers. */
export function DiagramPropertiesSplitResizableLayout({
  layout,
  persistLayout,
  handleClassName,
  className,
  properties,
  layers,
}: DiagramPropertiesSplitLayoutProps) {
  return (
    <PersistedResizablePanelGroup
      direction="vertical"
      layout={layout}
      persistLayout={persistLayout}
      normalizeLayout={normalizeDiagramGeneratorPropertiesSplitLayout}
      mins={DIAGRAM_GENERATOR_PROPERTIES_SPLIT_MIN}
      maxs={DIAGRAM_GENERATOR_PROPERTIES_SPLIT_MAX}
      className={className}
      handleClassName={handleClassName}
      panelClassName="min-h-0 overflow-hidden"
    >
      {[properties, layers]}
    </PersistedResizablePanelGroup>
  )
}
