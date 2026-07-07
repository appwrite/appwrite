import { useCallback, useEffect, useMemo, useRef } from 'react'
import { Database, Maximize2, ZoomIn, ZoomOut } from 'lucide-react'
import { SchemaBlueprintMat } from '@/components/global/shared/SchemaBlueprintMat'
import { SchemaVisualizerRelationshipEdges } from '@/components/global/shared/SchemaVisualizerRelationshipEdges'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useViewportPanZoom } from '@/lib/hooks/useViewportPanZoom'
import type { SchemaVisualizerRelationshipPath } from '@/lib/schema-visualizer-relationship-paths'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const NODE_HEADER_HEIGHT = 40
const SOLO_NODE_HEADER_HEIGHT = 48
const PRIMARY_NODE_WIDTH = 168
const SOLO_PRIMARY_NODE_WIDTH = 240
const REPLICA_NODE_WIDTH = 152
const VERTICAL_GAP = 72
const HORIZONTAL_GAP = 24
const CONTENT_PADDING = 80
const CONTENT_MIN_WIDTH = 640
const CONTENT_MIN_HEIGHT = 360
const VIEWPORT_HEIGHT = 280

type PostgresHaClusterDiagramProps = {
  replicaCount: number
  syncMode?: string
  className?: string
}

type DiagramNode = {
  id: string
  label: string
  x: number
  y: number
  width: number
  height: number
}

type DiagramLayout = {
  width: number
  height: number
  primary: DiagramNode
  replicas: DiagramNode[]
  paths: SchemaVisualizerRelationshipPath[]
  bounds: {
    minX: number
    minY: number
    maxX: number
    maxY: number
  }
}

function buildReplicationPaths(
  primary: DiagramNode,
  replicas: DiagramNode[],
): SchemaVisualizerRelationshipPath[] {
  if (replicas.length === 0) return []

  const primaryCenterX = primary.x + primary.width / 2
  const primaryBottomY = primary.y + primary.height

  if (replicas.length === 1) {
    const replica = replicas[0]
    const replicaCenterX = replica.x + replica.width / 2
    const replicaTopY = replica.y

    return [
      {
        d: `M ${primaryCenterX} ${primaryBottomY} L ${replicaCenterX} ${replicaTopY}`,
        from: { x: primaryCenterX, y: primaryBottomY, side: 'right' },
        to: { x: replicaCenterX, y: replicaTopY, side: 'left' },
      },
    ]
  }

  const branchY = primaryBottomY + VERTICAL_GAP / 2

  return replicas.map((replica) => {
    const replicaCenterX = replica.x + replica.width / 2
    const replicaTopY = replica.y

    return {
      d: [
        `M ${primaryCenterX} ${primaryBottomY}`,
        `L ${primaryCenterX} ${branchY}`,
        `L ${replicaCenterX} ${branchY}`,
        `L ${replicaCenterX} ${replicaTopY}`,
      ].join(' '),
      from: { x: primaryCenterX, y: primaryBottomY, side: 'right' },
      to: { x: replicaCenterX, y: replicaTopY, side: 'left' },
    }
  })
}

function buildDiagramLayout(replicaCount: number): DiagramLayout {
  const isSolo = replicaCount === 0
  const primaryHeight = isSolo ? SOLO_NODE_HEADER_HEIGHT : NODE_HEADER_HEIGHT
  const primaryWidth = isSolo ? SOLO_PRIMARY_NODE_WIDTH : PRIMARY_NODE_WIDTH
  const replicaHeight = NODE_HEADER_HEIGHT

  const replicaRowWidth =
    replicaCount > 0
      ? replicaCount * REPLICA_NODE_WIDTH +
        Math.max(0, replicaCount - 1) * HORIZONTAL_GAP
      : 0

  const clusterWidth = Math.max(primaryWidth, replicaRowWidth)
  const clusterHeight = isSolo
    ? primaryHeight
    : primaryHeight + VERTICAL_GAP + replicaHeight

  const width = Math.max(CONTENT_MIN_WIDTH, clusterWidth + CONTENT_PADDING * 2)
  const height = Math.max(CONTENT_MIN_HEIGHT, clusterHeight + CONTENT_PADDING * 2)

  const clusterStartX = width / 2 - clusterWidth / 2
  const clusterStartY = isSolo
    ? height / 2 - primaryHeight / 2
    : CONTENT_PADDING

  const primary: DiagramNode = {
    id: 'primary',
    label: 'Primary instance',
    x: clusterStartX + clusterWidth / 2 - primaryWidth / 2,
    y: clusterStartY,
    width: primaryWidth,
    height: primaryHeight,
  }

  const replicaY = primary.y + primaryHeight + VERTICAL_GAP
  const replicaStartX = width / 2 - replicaRowWidth / 2
  const replicas = Array.from({ length: replicaCount }, (_, index) => ({
    id: `replica-${index + 1}`,
    label: 'Read replica',
    x: replicaStartX + index * (REPLICA_NODE_WIDTH + HORIZONTAL_GAP),
    y: replicaY,
    width: REPLICA_NODE_WIDTH,
    height: replicaHeight,
  }))

  const nodes = [primary, ...replicas]
  const bounds = nodes.reduce(
    (acc, node) => ({
      minX: Math.min(acc.minX, node.x),
      minY: Math.min(acc.minY, node.y),
      maxX: Math.max(acc.maxX, node.x + node.width),
      maxY: Math.max(acc.maxY, node.y + node.height),
    }),
    {
      minX: Infinity,
      minY: Infinity,
      maxX: -Infinity,
      maxY: -Infinity,
    },
  )

  return {
    width,
    height,
    primary,
    replicas,
    paths: buildReplicationPaths(primary, replicas),
    bounds,
  }
}

function ClusterNodeCard({
  label,
  node,
  emphasized,
}: {
  label: string
  node: DiagramNode
  emphasized?: boolean
}) {
  return (
    <div
      className="absolute select-none"
      style={{
        left: `${node.x}px`,
        top: `${node.y}px`,
        width: `${node.width}px`,
      }}
    >
      <div className="overflow-hidden rounded-lg border bg-card">
        <div
          className={cn(
            'flex items-center gap-2 bg-muted/50 px-3',
            emphasized ? 'py-3' : 'py-2.5',
          )}
          style={{ minHeight: node.height }}
        >
          <Database
            className={cn(
              'shrink-0 text-muted-foreground',
              emphasized ? 'h-5 w-5' : 'h-4 w-4',
            )}
            aria-hidden
          />
          <span
            className={cn(
              'truncate font-medium text-foreground',
              emphasized ? 'text-[14px]' : 'text-[13px]',
            )}
          >
            {label}
          </span>
        </div>
      </div>
    </div>
  )
}

function fitLayoutToViewport(
  layout: DiagramLayout,
  canvas: HTMLDivElement,
  setZoom: (zoom: number) => void,
  setPan: (pan: { x: number; y: number }) => void,
) {
  const { bounds } = layout
  if (bounds.minX === Infinity) return

  const canvasWidth = canvas.clientWidth
  const canvasHeight = canvas.clientHeight
  const contentWidth = bounds.maxX - bounds.minX
  const contentHeight = bounds.maxY - bounds.minY
  const padding = 48
  const fitZoom = Math.min(
    canvasWidth / (contentWidth + padding * 2),
    canvasHeight / (contentHeight + padding * 2),
    1,
  )
  const centerX = (bounds.minX + bounds.maxX) / 2
  const centerY = (bounds.minY + bounds.maxY) / 2

  setZoom(fitZoom)
  setPan({
    x: canvasWidth / 2 - centerX * fitZoom,
    y: canvasHeight / 2 - centerY * fitZoom,
  })
}

export function PostgresHaClusterDiagram({
  replicaCount,
  className,
}: PostgresHaClusterDiagramProps) {
  const t = useT()
  const layout = useMemo(
    () => buildDiagramLayout(replicaCount),
    [replicaCount],
  )
  const hasAutoFocusedRef = useRef(false)
  const lastReplicaCountRef = useRef(replicaCount)

  const {
    canvasRef,
    zoom,
    pan,
    setZoom,
    setPan,
    isDragging,
    zoomPercentage,
    zoomInDisabled,
    zoomOutDisabled,
    bindCanvas,
    zoomIn,
    zoomOut,
  } = useViewportPanZoom()

  const fitToView = useCallback(() => {
    const canvas = canvasRef.current
    if (!canvas) return
    fitLayoutToViewport(layout, canvas, setZoom, setPan)
  }, [canvasRef, layout, setPan, setZoom])

  useEffect(() => {
    if (lastReplicaCountRef.current !== replicaCount) {
      hasAutoFocusedRef.current = false
      lastReplicaCountRef.current = replicaCount
    }

    if (hasAutoFocusedRef.current) return

    const canvas = canvasRef.current
    if (!canvas) return

    const frame = requestAnimationFrame(() => {
      fitLayoutToViewport(layout, canvas, setZoom, setPan)
      hasAutoFocusedRef.current = true
    })

    return () => cancelAnimationFrame(frame)
  }, [layout, replicaCount, canvasRef, setPan, setZoom])

  const ariaLabel = t('Cluster topology')

  return (
    <div className={cn('space-y-3', className)}>
      <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
        {t('Cluster topology')}
      </p>

      <div
        className="relative overflow-hidden rounded-lg border border-border"
        style={{ backgroundColor: 'hsl(var(--muted) / 0.3)' }}
      >
        <div className="pointer-events-none absolute end-3 top-3 z-30 flex items-center gap-1.5">
          <div className="pointer-events-auto flex items-center gap-1.5">
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
                  onClick={zoomIn}
                  disabled={zoomInDisabled}
                >
                  <ZoomIn className="h-4 w-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>{t('Zoom in')}</TooltipContent>
            </Tooltip>

            <div className="flex h-8 min-w-[52px] items-center justify-center rounded-md border border-border bg-card/95 px-2.5 backdrop-blur-sm">
              <span className="text-[11px] font-medium tabular-nums text-foreground">
                {zoomPercentage}%
              </span>
            </div>

            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
                  onClick={zoomOut}
                  disabled={zoomOutDisabled}
                >
                  <ZoomOut className="h-4 w-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>{t('Zoom out')}</TooltipContent>
            </Tooltip>

            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
                  onClick={fitToView}
                >
                  <Maximize2 className="h-4 w-4" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>{t('Fit to view')}</TooltipContent>
            </Tooltip>
          </div>
        </div>

        <div
          ref={canvasRef}
          className={cn(
            'w-full cursor-grab overflow-hidden select-none',
            isDragging && 'cursor-grabbing',
          )}
          style={{ height: VIEWPORT_HEIGHT }}
          role="application"
          aria-label={ariaLabel}
          {...bindCanvas}
        >
          <div
            className="relative h-full w-full"
            style={{
              transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`,
              transformOrigin: '0 0',
            }}
          >
            <SchemaBlueprintMat density="dense" />

            <SchemaVisualizerRelationshipEdges
              paths={layout.paths}
              extent={{ width: layout.width, height: layout.height }}
            />

            <div className="relative z-20">
              <ClusterNodeCard
                label={t('Primary instance')}
                node={layout.primary}
                emphasized={replicaCount === 0}
              />

              {layout.replicas.map((replica, index) => (
                <ClusterNodeCard
                  key={replica.id}
                  label={`${t('Read replica')} ${index + 1}`}
                  node={replica}
                />
              ))}
            </div>
          </div>
        </div>
      </div>

      <p className="text-[12px] leading-relaxed text-muted-foreground">
        {replicaCount === 0
          ? t(
              'A single primary handles reads and writes. Add replicas to scale read traffic and improve failover.',
            )
          : t(
              'The primary accepts writes and replicates changes to read replicas for query scaling and faster recovery.',
            )}
      </p>
    </div>
  )
}
