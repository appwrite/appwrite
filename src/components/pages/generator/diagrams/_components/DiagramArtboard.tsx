import { useMemo } from 'react'
import { buildDiagramEdgePaths } from '@/lib/diagram-generator/edge-paths'
import { getCoverBrandThemeForSvgExport } from '@/lib/cover-generator/brand-theme'
import type {
  DiagramConnectDraft,
  DiagramDocument,
  DiagramSelection,
} from '@/lib/diagram-generator/types'
import { CoverBrandBackgroundPreview } from '@/lib/cover-generator/cover-brand-background-preview'
import { DiagramAlignGuidesLayer } from '@/components/pages/generator/diagrams/_components/DiagramAlignGuidesLayer'
import { DiagramEdgesLayer } from '@/components/pages/generator/diagrams/_components/DiagramEdgesLayer'
import { DiagramNodeView } from '@/components/pages/generator/diagrams/_components/DiagramNodeView'
import { getDiagramConnectPreviewPath } from '@/components/pages/generator/diagrams/_components/DiagramNodeChrome'
import type { DiagramAnchorSide } from '@/lib/diagram-generator/types'
import type { DiagramAlignGuide } from '@/lib/diagram-generator/node-align-snap'
import { getSelectedNodeIds } from '@/lib/diagram-generator/selection'
import { cn } from '@/lib/utils'

type DiagramArtboardProps = {
  document: DiagramDocument
  width: number
  height: number
  /** When true, background is rendered by the canvas frame layer instead. */
  hideBackground?: boolean
  interactive?: boolean
  selection?: DiagramSelection
  connectDraft?: DiagramConnectDraft | null
  connectPreviewPoint?: { x: number; y: number } | null
  onNodePointerDown?: (
    event: React.PointerEvent<HTMLDivElement>,
    nodeId: string,
  ) => void
  onNodeClick?: (
    nodeId: string,
    event: React.MouseEvent<HTMLDivElement>,
  ) => void
  onPortPointerDown?: (
    nodeId: string,
    side: DiagramAnchorSide,
    event: React.PointerEvent<HTMLButtonElement>,
  ) => void
  onResizePointerDown?: (
    event: React.PointerEvent<HTMLButtonElement>,
    nodeId: string,
  ) => void
  onEdgeClick?: (edgeId: string) => void
  onCanvasClick?: () => void
  onCanvasPointerDown?: (event: React.PointerEvent<HTMLDivElement>) => void
  onPointerMove?: (event: React.PointerEvent<HTMLDivElement>) => void
  alignGuides?: DiagramAlignGuide[]
  marqueeRect?: { x: number; y: number; width: number; height: number } | null
  className?: string
}

export function DiagramArtboard({
  document,
  width,
  height,
  hideBackground = false,
  interactive = false,
  selection,
  connectDraft = null,
  connectPreviewPoint = null,
  onNodePointerDown,
  onNodeClick,
  onPortPointerDown,
  onResizePointerDown,
  onEdgeClick,
  onCanvasClick,
  onCanvasPointerDown,
  onPointerMove,
  alignGuides = [],
  marqueeRect = null,
  className,
}: DiagramArtboardProps) {
  const brand = getCoverBrandThemeForSvgExport(document.theme)
  const edgePaths = buildDiagramEdgePaths(document.nodes, document.edges)
  const selectedNodeIds = getSelectedNodeIds(selection ?? { type: 'none' })
  const selectedNodeCount = selectedNodeIds.length
  const selectedNodeIdSet = useMemo(
    () => new Set(selectedNodeIds),
    [selectedNodeIds],
  )

  const renderNodes = useMemo(() => {
    const entries = document.nodes.map((node, stackIndex) => ({ node, stackIndex }))

    if (!interactive || selectedNodeIds.length === 0 || document.nodes.length <= 1) {
      return entries
    }

    const selected = entries.filter((entry) => selectedNodeIdSet.has(entry.node.id))
    const rest = entries.filter((entry) => !selectedNodeIdSet.has(entry.node.id))

    return [
      ...rest,
      ...selected.map((entry, index) => ({
        ...entry,
        stackIndex: document.nodes.length - selected.length + index,
      })),
    ]
  }, [document.nodes, interactive, selectedNodeIdSet, selectedNodeIds.length])

  const draftNode = connectDraft
    ? document.nodes.find((node) => node.id === connectDraft.nodeId)
    : undefined
  const connectPreviewPath =
    draftNode && connectPreviewPoint
      ? getDiagramConnectPreviewPath(draftNode, connectDraft!.side, connectPreviewPoint)
      : null

  return (
    <div
      className={cn(
        'relative',
        interactive ? 'overflow-visible' : 'overflow-hidden',
        className,
      )}
      style={{ width, height }}
      onPointerDown={interactive ? onCanvasPointerDown : undefined}
      onMouseDown={
        interactive
          ? (event) => {
              const target = event.target as HTMLElement
              if (
                target.closest(
                  '[data-diagram-node],[data-diagram-edge],[data-diagram-port],[data-diagram-resize]',
                )
              ) {
                return
              }
              event.stopPropagation()
            }
          : undefined
      }
      onPointerMove={interactive ? onPointerMove : undefined}
      onClick={
        interactive
          ? (event) => {
              const target = event.target as HTMLElement
              if (
                target.closest(
                  '[data-diagram-node],[data-diagram-edge],[data-diagram-port],[data-diagram-resize]',
                )
              ) {
                return
              }
              event.stopPropagation()
              onCanvasClick?.()
            }
          : undefined
      }
    >
      {hideBackground ? null : (
        <div className="absolute inset-0 overflow-hidden">
          <CoverBrandBackgroundPreview
            themeId={document.theme}
            width={width}
            height={height}
          />
        </div>
      )}

      <svg
        className="pointer-events-none absolute inset-0 z-[1]"
        width={width}
        height={height}
        viewBox={`0 0 ${width} ${height}`}
        overflow={interactive ? 'visible' : 'hidden'}
        aria-hidden
      >
        <DiagramEdgesLayer
          paths={edgePaths}
          brand={brand}
          layer="strokes"
          selectedEdgeId={selection?.type === 'edge' ? selection.id : null}
        />
        {connectPreviewPath ? (
          <path
            d={connectPreviewPath}
            fill="none"
            stroke={brand.brandCta}
            strokeWidth={2}
            strokeDasharray="6 5"
            strokeLinecap="round"
            opacity={0.45}
            pointerEvents="none"
          />
        ) : null}
      </svg>

      <svg
        className="pointer-events-none absolute inset-0 z-[2]"
        width={width}
        height={height}
        viewBox={`0 0 ${width} ${height}`}
        overflow={interactive ? 'visible' : 'hidden'}
        aria-hidden
      >
        <DiagramEdgesLayer
          paths={edgePaths}
          brand={brand}
          layer="labels"
          selectedEdgeId={selection?.type === 'edge' ? selection.id : null}
        />
      </svg>

      {interactive && !connectDraft ? (
        <svg
          className="pointer-events-none absolute inset-0 z-[3] overflow-visible"
          width={width}
          height={height}
          viewBox={`0 0 ${width} ${height}`}
          overflow="visible"
          aria-hidden
        >
          <DiagramEdgesLayer
            paths={edgePaths}
            brand={brand}
            layer="hit"
            selectedEdgeId={selection?.type === 'edge' ? selection.id : null}
            onEdgeClick={onEdgeClick}
          />
        </svg>
      ) : null}

      <div className="pointer-events-none absolute inset-0 z-10 overflow-visible">
        {renderNodes.map(({ node, stackIndex }) => {
          const isSelected = selectedNodeIdSet.has(node.id)
          const isConnectTarget = Boolean(connectDraft && connectDraft.nodeId !== node.id)
          const showPorts =
            Boolean(connectDraft) ||
            isConnectTarget ||
            (isSelected && selectedNodeCount === 1)

          return (
            <DiagramNodeView
              key={node.id}
              node={node}
              stackIndex={stackIndex}
              brand={brand}
              themeId={document.theme}
              selected={isSelected}
              showPorts={showPorts}
              connectDraftSide={
                connectDraft?.nodeId === node.id ? connectDraft.side : null
              }
              interactive={interactive}
              onPointerDown={
                interactive && onNodePointerDown
                  ? (event) => onNodePointerDown(event, node.id)
                  : undefined
              }
              onClick={
                interactive && onNodeClick
                  ? (event) => onNodeClick(node.id, event)
                  : undefined
              }
              onPortPointerDown={
                interactive && onPortPointerDown
                  ? (side, event) => onPortPointerDown(node.id, side, event)
                  : undefined
              }
              onResizePointerDown={
                interactive && onResizePointerDown && isSelected && selectedNodeCount === 1
                  ? (event) => onResizePointerDown(event, node.id)
                  : undefined
              }
            />
          )
        })}
      </div>

      <svg
        className="pointer-events-none absolute inset-0 z-[11]"
        width={width}
        height={height}
        viewBox={`0 0 ${width} ${height}`}
        overflow={interactive ? 'visible' : 'hidden'}
        aria-hidden
      >
        <DiagramEdgesLayer
          paths={edgePaths}
          brand={brand}
          layer="arrowheads"
          selectedEdgeId={selection?.type === 'edge' ? selection.id : null}
        />
      </svg>

      {interactive && alignGuides.length > 0 ? (
        <DiagramAlignGuidesLayer
          guides={alignGuides}
          brand={brand}
          width={width}
          height={height}
        />
      ) : null}

      {interactive && marqueeRect ? (
        <div
          className="pointer-events-none absolute z-20 border border-[var(--brand-cta)] bg-[var(--brand-cta)]/10"
          style={{
            left: Math.min(marqueeRect.x, marqueeRect.x + marqueeRect.width),
            top: Math.min(marqueeRect.y, marqueeRect.y + marqueeRect.height),
            width: Math.abs(marqueeRect.width),
            height: Math.abs(marqueeRect.height),
          }}
        />
      ) : null}
    </div>
  )
}
