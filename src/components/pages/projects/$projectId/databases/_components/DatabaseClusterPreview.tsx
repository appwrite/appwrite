import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  type KeyboardEvent,
} from 'react'
import {
  Database,
  Maximize2,
  Network,
  ZoomIn,
  ZoomOut,
  type LucideIcon,
} from 'lucide-react'
import { SchemaBlueprintMat } from '@/components/global/shared/SchemaBlueprintMat'
import { SchemaVisualizerRelationshipEdges } from '@/components/global/shared/SchemaVisualizerRelationshipEdges'
import type { SchemaVisualizerRelationshipPath } from '@/lib/schema-visualizer-relationship-paths'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  useViewportPanZoom,
  VIEWPORT_PAN_ZOOM_MAX,
} from '@/lib/hooks/useViewportPanZoom'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

/** Mid-card cluster area; taller so node headers + live metric placeholders stay readable. */
export const DATABASE_CLUSTER_PREVIEW_HEIGHT = 200
/** Interactive settings viewport (replication HA diagram). */
export const DATABASE_CLUSTER_INTERACTIVE_HEIGHT = 280
/** Interactive viewport when the connection-pooler proxy row is included. */
export const DATABASE_CLUSTER_INTERACTIVE_HEIGHT_WITH_PROXY = 340
/** Approximate full-bleed card inner width (no side padding on the diagram). */
const PREVIEW_FIT_WIDTH = 340

const HEADER_HEIGHT = 34
const METRICS_BODY_HEIGHT = 28
const NODE_HEIGHT = HEADER_HEIGHT + METRICS_BODY_HEIGHT
/** Wide enough for single-line CPU + Memory metrics with a separator. */
const NODE_MIN_WIDTH = 148
const VERTICAL_GAP = 28
const HORIZONTAL_GAP = 12
const CONTENT_PADDING_X = 16
const CONTENT_PADDING_Y = 14
/** Icon (14) + gap (6) + status dot (8) + gap (6) + horizontal padding (16). */
const NODE_CHROME_WIDTH = 14 + 6 + 8 + 6 + 16
const NODE_LABEL_FONT = '500 11px ui-sans-serif, system-ui, sans-serif'

/** Member/pod status used by cluster node indicators. */
export type ClusterNodeStatus =
  | 'active'
  | 'provisioning'
  | 'starting'
  | 'failed'
  | 'pending'
  | 'unknown'

/** Connection usage shown on the pooler/proxy node. */
export type DatabaseClusterProxyConnections = {
  current: number | null
  max: number | null
}

/**
 * Optional connection-pooler node for the cluster topology diagram.
 * Pass engine-specific labels via `resolveClusterProxyLabel` so MySQL,
 * Postgres, and Appwrite product DBs can reuse the same diagram.
 */
export type DatabaseClusterProxy = {
  label: string
  connections?: DatabaseClusterProxyConnections | null
  status?: ClusterNodeStatus | string | null | undefined
}

/**
 * Resolve the connection-pooler label for the cluster topology diagram.
 * Dedicated engines can show product names (PgDog, ProxySQL). Appwrite
 * product databases (TablesDB, DocumentsDB, VectorsDB) stay generic.
 */
export function resolveClusterProxyLabel(options: {
  /** Dedicated engine id, e.g. `postgres`, `postgresql`, `mysql`. */
  engine?: string | null
  /**
   * Dedicated `api` field (`tablesdb` / `documentsdb` / `vectorsdb` / `nativedb`).
   * Product-owned APIs hide vendor proxy names automatically.
   */
  api?: string | null
  /**
   * When true, hide vendor proxy names and use a generic "Proxy" label.
   * Prefer passing `api` for Appwrite product databases when available.
   */
  hideProxyProductName?: boolean
  t: (text: string) => string
}): string {
  const api = String(options.api ?? '')
    .trim()
    .toLowerCase()
  const isAppwriteProductDb =
    options.hideProxyProductName === true ||
    api === 'tablesdb' ||
    api === 'documentsdb' ||
    api === 'vectorsdb'
  if (isAppwriteProductDb) return options.t('Proxy')

  const engine = String(options.engine ?? '')
    .trim()
    .toLowerCase()
  if (
    engine === 'postgres' ||
    engine === 'postgresql'
  ) {
    return 'PgDog'
  }
  if (engine === 'mysql' || engine === 'mariadb') return 'ProxySQL'
  return options.t('Proxy')
}

function normalizeClusterNodeStatus(status?: string | null): ClusterNodeStatus {
  const normalized = String(status ?? '')
    .trim()
    .toLowerCase()
  if (normalized === 'active' || normalized === 'ready') return 'active'
  if (
    normalized === 'provisioning' ||
    normalized === 'scaling' ||
    normalized === 'restoring'
  ) {
    return 'provisioning'
  }
  if (normalized === 'starting') return 'starting'
  if (
    normalized === 'failed' ||
    normalized === 'deleted' ||
    normalized === 'error' ||
    normalized === 'notfound'
  ) {
    return 'failed'
  }
  if (
    normalized === 'pending' ||
    normalized === 'paused' ||
    normalized === 'inactive'
  ) {
    return 'pending'
  }
  return 'unknown'
}

/**
 * Build primary + replica node statuses from a dedicated database lifecycle status
 * when per-member HA statuses are not loaded on the list.
 */
export function clusterNodeStatusesFromDatabaseStatus(
  databaseStatus: string | null | undefined,
  replicaCount: number,
): ClusterNodeStatus[] {
  const status = normalizeClusterNodeStatus(databaseStatus)
  const safeReplicaCount = Math.max(0, Math.floor(replicaCount))
  return [status, ...Array.from({ length: safeReplicaCount }, () => status)]
}

function clusterNodeStatusDotClass(status: ClusterNodeStatus): string {
  switch (status) {
    case 'active':
      return 'bg-emerald-500 dark:bg-emerald-400'
    case 'provisioning':
    case 'starting':
    case 'pending':
      return 'bg-amber-500 dark:bg-amber-400'
    case 'failed':
      return 'bg-red-500 dark:bg-red-400'
    default:
      return 'bg-slate-500 dark:bg-slate-400'
  }
}

function clusterNodeStatusLabel(
  status: ClusterNodeStatus,
  t: ReturnType<typeof useT>,
): string {
  switch (status) {
    case 'active':
      return t('Active')
    case 'provisioning':
      return t('Provisioning')
    case 'starting':
      return t('Starting')
    case 'failed':
      return t('Failed')
    case 'pending':
      return t('Pending')
    default:
      return t('Unknown')
  }
}

type DiagramNode = {
  id: string
  label: string
  x: number
  y: number
  width: number
  height: number
}

type CompactLayout = {
  width: number
  height: number
  proxy: DiagramNode | null
  primary: DiagramNode
  replicas: DiagramNode[]
  paths: SchemaVisualizerRelationshipPath[]
}

type ClusterNodeMetrics =
  | { kind: 'resource'; cpu: number; memory: number }
  | { kind: 'connections'; current: number | null; max: number | null }

let measureCanvas: HTMLCanvasElement | null = null

function measureLabelWidth(label: string): number {
  if (typeof document === 'undefined') {
    return Math.ceil(label.length * 7)
  }
  measureCanvas ??= document.createElement('canvas')
  const ctx = measureCanvas.getContext('2d')
  if (!ctx) return Math.ceil(label.length * 7)
  ctx.font = NODE_LABEL_FONT
  return Math.ceil(ctx.measureText(label).width)
}

function nodeWidthForLabel(label: string): number {
  return Math.max(NODE_MIN_WIDTH, NODE_CHROME_WIDTH + measureLabelWidth(label))
}

function buildCompactPaths(
  proxy: DiagramNode | null,
  primary: DiagramNode,
  replicas: DiagramNode[],
): SchemaVisualizerRelationshipPath[] {
  const paths: SchemaVisualizerRelationshipPath[] = []

  if (proxy) {
    const proxyCenterX = proxy.x + proxy.width / 2
    const proxyBottomY = proxy.y + proxy.height
    const primaryCenterX = primary.x + primary.width / 2
    const primaryTopY = primary.y
    paths.push({
      d: `M ${proxyCenterX} ${proxyBottomY} L ${primaryCenterX} ${primaryTopY}`,
      from: { x: proxyCenterX, y: proxyBottomY, side: 'right' },
      to: { x: primaryCenterX, y: primaryTopY, side: 'left' },
    })
  }

  if (replicas.length === 0) return paths

  const primaryCenterX = primary.x + primary.width / 2
  const primaryBottomY = primary.y + primary.height

  if (replicas.length === 1) {
    const replica = replicas[0]
    const replicaCenterX = replica.x + replica.width / 2
    const replicaTopY = replica.y
    paths.push({
      d: `M ${primaryCenterX} ${primaryBottomY} L ${replicaCenterX} ${replicaTopY}`,
      from: { x: primaryCenterX, y: primaryBottomY, side: 'right' },
      to: { x: replicaCenterX, y: replicaTopY, side: 'left' },
    })
    return paths
  }

  const branchY = primaryBottomY + VERTICAL_GAP / 2
  for (const replica of replicas) {
    const replicaCenterX = replica.x + replica.width / 2
    const replicaTopY = replica.y
    paths.push({
      d: [
        `M ${primaryCenterX} ${primaryBottomY}`,
        `L ${primaryCenterX} ${branchY}`,
        `L ${replicaCenterX} ${branchY}`,
        `L ${replicaCenterX} ${replicaTopY}`,
      ].join(' '),
      from: { x: primaryCenterX, y: primaryBottomY, side: 'right' },
      to: { x: replicaCenterX, y: replicaTopY, side: 'left' },
    })
  }
  return paths
}

function buildCompactLayout(
  primaryLabel: string,
  replicaLabels: string[],
  proxyLabel?: string | null,
): CompactLayout {
  const hasProxy = Boolean(proxyLabel)
  const isSolo = replicaLabels.length === 0
  const proxyWidth = hasProxy ? nodeWidthForLabel(proxyLabel!) : 0
  const primaryWidth = nodeWidthForLabel(primaryLabel)
  const replicaWidths = replicaLabels.map((label) => nodeWidthForLabel(label))
  const replicaRowWidth =
    replicaWidths.length > 0
      ? replicaWidths.reduce((sum, width) => sum + width, 0) +
        Math.max(0, replicaWidths.length - 1) * HORIZONTAL_GAP
      : 0
  const clusterWidth = Math.max(proxyWidth, primaryWidth, replicaRowWidth)
  const rowCount = 1 + (hasProxy ? 1 : 0) + (isSolo ? 0 : 1)
  const clusterHeight =
    rowCount * NODE_HEIGHT + Math.max(0, rowCount - 1) * VERTICAL_GAP

  const width = clusterWidth + CONTENT_PADDING_X * 2
  const height = clusterHeight + CONTENT_PADDING_Y * 2

  const clusterStartX = CONTENT_PADDING_X
  let nextY = CONTENT_PADDING_Y

  const proxy: DiagramNode | null = hasProxy
    ? {
        id: 'proxy',
        label: proxyLabel!,
        x: clusterStartX + clusterWidth / 2 - proxyWidth / 2,
        y: nextY,
        width: proxyWidth,
        height: NODE_HEIGHT,
      }
    : null
  if (proxy) nextY += NODE_HEIGHT + VERTICAL_GAP

  const primary: DiagramNode = {
    id: 'primary',
    label: primaryLabel,
    x: clusterStartX + clusterWidth / 2 - primaryWidth / 2,
    y: nextY,
    width: primaryWidth,
    height: NODE_HEIGHT,
  }
  nextY += NODE_HEIGHT + VERTICAL_GAP

  const replicaStartX = width / 2 - replicaRowWidth / 2
  let replicaX = replicaStartX
  const replicas = replicaLabels.map((label, index) => {
    const nodeWidth = replicaWidths[index]!
    const node: DiagramNode = {
      id: `replica-${index + 1}`,
      label,
      x: replicaX,
      y: nextY,
      width: nodeWidth,
      height: NODE_HEIGHT,
    }
    replicaX += nodeWidth + HORIZONTAL_GAP
    return node
  })

  return {
    width,
    height,
    proxy,
    primary,
    replicas,
    paths: buildCompactPaths(proxy, primary, replicas),
  }
}

function CompactClusterNode({
  label,
  node,
  status,
  emphasized,
  icon: Icon = Database,
  metrics,
}: {
  label: string
  node: DiagramNode
  status: ClusterNodeStatus
  emphasized?: boolean
  icon?: LucideIcon
  metrics: ClusterNodeMetrics
}) {
  const t = useT()
  const statusLabel = clusterNodeStatusLabel(status, t)

  const connectionsLabel =
    metrics.kind === 'connections'
      ? `${
          metrics.current == null ? '—' : metrics.current.toLocaleString()
        } / ${metrics.max == null ? '—' : metrics.max.toLocaleString()}`
      : null

  return (
    <div
      className="absolute select-none"
      style={{
        left: `${node.x}px`,
        top: `${node.y}px`,
        width: `${node.width}px`,
      }}
    >
      <div className="overflow-hidden rounded-md border border-border bg-card shadow-sm">
        <div
          className={cn(
            'flex items-center gap-1.5 bg-muted/50 px-2',
            emphasized ? 'py-2' : 'py-1.5',
          )}
          style={{ minHeight: HEADER_HEIGHT }}
        >
          <Icon
            className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
            aria-hidden
          />
          <span className="min-w-0 flex-1 whitespace-nowrap text-[11px] font-medium text-foreground">
            {label}
          </span>
          <span
            className={cn(
              'h-2 w-2 shrink-0 rounded-full',
              clusterNodeStatusDotClass(status),
            )}
            title={statusLabel}
            aria-label={statusLabel}
          />
        </div>
        <div
          className="flex items-center justify-center gap-2 border-t border-border/60 bg-card px-2.5 py-1.5"
          style={{ minHeight: METRICS_BODY_HEIGHT }}
        >
          {metrics.kind === 'connections' ? (
            <span className="inline-flex items-center gap-1 text-[10px] leading-none">
              <span className="text-muted-foreground">{t('Connections')}</span>
              <span className="font-mono tabular-nums font-medium text-foreground">
                {connectionsLabel}
              </span>
            </span>
          ) : (
            <>
              <span className="inline-flex items-center gap-1 text-[10px] leading-none">
                <span className="text-muted-foreground">{t('CPU')}</span>
                <span className="font-mono tabular-nums font-medium text-foreground">
                  {metrics.cpu}%
                </span>
              </span>
              <span
                className="h-3 w-px shrink-0 bg-border"
                aria-hidden
              />
              <span className="inline-flex items-center gap-1 text-[10px] leading-none">
                <span className="text-muted-foreground">{t('Memory')}</span>
                <span className="font-mono tabular-nums font-medium text-foreground">
                  {metrics.memory}%
                </span>
              </span>
            </>
          )}
        </div>
      </div>
    </div>
  )
}

/** Stable mock metrics per node index until live pod metrics are wired. */
function mockNodeMetrics(nodeIndex: number): {
  cpu: number
  memory: number
} {
  const mocks = [
    { cpu: 18, memory: 42 },
    { cpu: 11, memory: 31 },
    { cpu: 7, memory: 28 },
    { cpu: 14, memory: 36 },
    { cpu: 9, memory: 27 },
  ]
  return mocks[nodeIndex % mocks.length]!
}

/** Mock total connections for a cluster card footer until live metrics are wired. */
export function mockDatabaseConnections(seed = 0): number {
  const mocks = [1, 18, 12, 30, 9]
  return mocks[Math.abs(seed) % mocks.length]!
}

function fitCompactLayoutToViewport(
  layout: CompactLayout,
  canvas: HTMLDivElement,
  setZoom: (zoom: number) => void,
  setPan: (pan: { x: number; y: number }) => void,
) {
  const canvasWidth = canvas.clientWidth
  const canvasHeight = canvas.clientHeight
  if (canvasWidth <= 0 || canvasHeight <= 0) return

  const padding = 48
  const fitZoom = Math.min(
    VIEWPORT_PAN_ZOOM_MAX,
    canvasWidth / (layout.width + padding * 2),
    canvasHeight / (layout.height + padding * 2),
  )
  const centerX = layout.width / 2
  const centerY = layout.height / 2
  setZoom(fitZoom)
  setPan({
    x: canvasWidth / 2 - centerX * fitZoom,
    y: canvasHeight / 2 - centerY * fitZoom,
  })
}

type DatabaseClusterPreviewProps = {
  replicaCount: number
  /**
   * Per-node status for primary then each replica.
   * Falls back to `active` when omitted or shorter than the node list.
   */
  nodeStatuses?: Array<ClusterNodeStatus | string | null | undefined>
  /**
   * Optional connection-pooler node above the primary.
   * Reuse across engines by passing a label from `resolveClusterProxyLabel`
   * and connection usage for the database tier.
   */
  proxy?: DatabaseClusterProxy | null
  className?: string
  /** When true, wrap with the same card section divider as project request charts. */
  withSectionDivider?: boolean
  /**
   * Enable pan/zoom controls (buttons, wheel, drag). Used on replication settings;
   * leave off for compact list cards.
   */
  interactive?: boolean
}

function ClusterDiagramNodes({
  layout,
  statuses,
  soloPrimary,
  proxy,
}: {
  layout: CompactLayout
  statuses: ClusterNodeStatus[]
  soloPrimary: boolean
  proxy?: DatabaseClusterProxy | null
}) {
  return (
    <>
      <SchemaBlueprintMat density="dense" />
      <SchemaVisualizerRelationshipEdges
        paths={layout.paths}
        extent={{ width: layout.width, height: layout.height }}
        showArrowHeads={false}
      />
      <div className="relative z-20">
        {layout.proxy ? (
          <CompactClusterNode
            label={layout.proxy.label}
            node={layout.proxy}
            status={normalizeClusterNodeStatus(proxy?.status ?? 'active')}
            icon={Network}
            metrics={{
              kind: 'connections',
              current: proxy?.connections?.current ?? null,
              max: proxy?.connections?.max ?? null,
            }}
          />
        ) : null}
        <CompactClusterNode
          label={layout.primary.label}
          node={layout.primary}
          status={statuses[0] ?? 'active'}
          emphasized={soloPrimary && !layout.proxy}
          metrics={{ kind: 'resource', ...mockNodeMetrics(0) }}
        />
        {layout.replicas.map((replica, index) => (
          <CompactClusterNode
            key={replica.id}
            label={replica.label}
            node={replica}
            status={statuses[index + 1] ?? 'active'}
            metrics={{ kind: 'resource', ...mockNodeMetrics(index + 1) }}
          />
        ))}
      </div>
    </>
  )
}

/**
 * Compact cluster topology for database resource cards and replication settings.
 * Pass `interactive` for pan/zoom on the settings diagram.
 * Pass `proxy` to include the connection-pooler node (reusable across engines).
 */
export function DatabaseClusterPreview({
  replicaCount,
  nodeStatuses,
  proxy,
  className,
  withSectionDivider = true,
  interactive = false,
}: DatabaseClusterPreviewProps) {
  const t = useT()
  const safeReplicaCount = Math.max(0, Math.floor(replicaCount))
  const primaryLabel = t('Primary')
  const proxyLabel = proxy?.label?.trim() || null
  const replicaLabels = useMemo(
    () =>
      Array.from(
        { length: safeReplicaCount },
        (_, index) => `${t('Replica')} ${index + 1}`,
      ),
    [safeReplicaCount, t],
  )
  const layout = useMemo(
    () => buildCompactLayout(primaryLabel, replicaLabels, proxyLabel),
    [primaryLabel, replicaLabels, proxyLabel],
  )
  const resolvedStatuses = useMemo(() => {
    const total = 1 + safeReplicaCount
    return Array.from({ length: total }, (_, index) =>
      normalizeClusterNodeStatus(nodeStatuses?.[index] ?? 'active'),
    )
  }, [nodeStatuses, safeReplicaCount])

  const interactiveHeight = proxyLabel
    ? DATABASE_CLUSTER_INTERACTIVE_HEIGHT_WITH_PROXY
    : DATABASE_CLUSTER_INTERACTIVE_HEIGHT

  const ariaLabel = useMemo(() => {
    const parts: string[] = []
    if (proxyLabel) parts.push(proxyLabel)
    parts.push(t('Primary'))
    if (safeReplicaCount > 0) {
      parts.push(
        `${safeReplicaCount} ${
          safeReplicaCount === 1 ? t('Replica') : t('Replicas')
        }`,
      )
    }
    return parts.join(' + ')
  }, [proxyLabel, safeReplicaCount, t])

  const hasAutoFocusedRef = useRef(false)
  const lastReplicaCountRef = useRef(replicaCount)
  const lastProxyLabelRef = useRef(proxyLabel)
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
    fitCompactLayoutToViewport(layout, canvas, setZoom, setPan)
  }, [canvasRef, layout, setPan, setZoom])

  useEffect(() => {
    if (!interactive) return

    if (
      lastReplicaCountRef.current !== replicaCount ||
      lastProxyLabelRef.current !== proxyLabel
    ) {
      hasAutoFocusedRef.current = false
      lastReplicaCountRef.current = replicaCount
      lastProxyLabelRef.current = proxyLabel
    }
    if (hasAutoFocusedRef.current) return

    const canvas = canvasRef.current
    if (!canvas) return

    const frame = requestAnimationFrame(() => {
      fitCompactLayoutToViewport(layout, canvas, setZoom, setPan)
      hasAutoFocusedRef.current = true
    })
    return () => cancelAnimationFrame(frame)
  }, [interactive, layout, replicaCount, proxyLabel, canvasRef, setPan, setZoom])

  const handleKeyDown = useCallback(
    (event: KeyboardEvent<HTMLDivElement>) => {
      if (!interactive) return
      const key = event.key
      if (key === '+' || key === '=') {
        event.preventDefault()
        zoomIn()
      } else if (key === '-' || key === '_') {
        event.preventDefault()
        zoomOut()
      } else if (key === '0') {
        event.preventDefault()
        fitToView()
      }
    },
    [fitToView, interactive, zoomIn, zoomOut],
  )

  const content = interactive ? (
    <div
      className={cn('relative min-w-0', className)}
      style={{
        height: interactiveHeight,
        backgroundColor: 'hsl(var(--muted) / 0.3)',
      }}
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
          'h-full w-full cursor-grab overflow-hidden select-none',
          isDragging && 'cursor-grabbing',
        )}
        role="application"
        aria-label={ariaLabel}
        tabIndex={0}
        onKeyDown={handleKeyDown}
        {...bindCanvas}
      >
        <div
          className="relative"
          style={{
            width: layout.width,
            height: layout.height,
            transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`,
            transformOrigin: '0 0',
          }}
        >
          <ClusterDiagramNodes
            layout={layout}
            statuses={resolvedStatuses}
            soloPrimary={safeReplicaCount === 0}
            proxy={proxy}
          />
        </div>
      </div>
    </div>
  ) : (
    <div
      className={cn('min-w-0', className)}
      style={{ height: DATABASE_CLUSTER_PREVIEW_HEIGHT }}
      role="img"
      aria-label={ariaLabel}
    >
      <div
        className="relative h-full w-full overflow-hidden"
        style={{ backgroundColor: 'hsl(var(--muted) / 0.3)' }}
      >
        <div
          className="absolute left-1/2 top-1/2"
          style={{
            width: layout.width,
            height: layout.height,
            transform: `translate(-50%, -50%) scale(${Math.min(
              1,
              (DATABASE_CLUSTER_PREVIEW_HEIGHT - 4) / layout.height,
              (PREVIEW_FIT_WIDTH - 4) / layout.width,
            )})`,
            transformOrigin: 'center center',
          }}
        >
          <ClusterDiagramNodes
            layout={layout}
            statuses={resolvedStatuses}
            soloPrimary={safeReplicaCount === 0}
            proxy={proxy}
          />
        </div>
      </div>
    </div>
  )

  if (!withSectionDivider) return content

  return (
    <div
      className={cn(
        '-mx-4 mt-2 min-w-0 shrink-0 border-t border-border',
      )}
      aria-hidden={false}
    >
      {content}
    </div>
  )
}
