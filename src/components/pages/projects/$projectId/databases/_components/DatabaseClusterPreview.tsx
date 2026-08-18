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
import { RefreshButton } from '@/components/global/shared/RefreshButton'
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
  | 'scaling'
  | 'starting'
  | 'failed'
  | 'pending'
  | 'adding'
  | 'removing'
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
   * Dedicated `api` field (`tablesdb` / `documentsdb` / `vectorsdb`, or an
   * engine name for native databases).
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
  if (normalized === 'provisioning' || normalized === 'restoring') {
    return 'provisioning'
  }
  if (normalized === 'scaling') return 'scaling'
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
  if (normalized === 'adding') return 'adding'
  if (normalized === 'removing') return 'removing'
  return 'unknown'
}

/**
 * Compute-tier scaling keeps the cluster available, so existing nodes should
 * keep live metrics and only switch the status dot to amber. Leave draft
 * add/remove and failed states alone.
 */
function overlayScalingLifecycle(
  status: ClusterNodeStatus,
  lifecycleStatus: ClusterNodeStatus,
): ClusterNodeStatus {
  if (lifecycleStatus !== 'scaling') return status
  if (status === 'adding' || status === 'removing' || status === 'failed') {
    return status
  }
  return 'scaling'
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

/**
 * Build primary + replica statuses from getReplicas members.
 * Pads missing replica slots as `provisioning` so the diagram matches the
 * configured replica count while pods are still coming up.
 */
export function clusterNodeStatusesFromMembers(
  members: Array<{ role?: string | null; status?: string | null }>,
  replicaCount: number,
  fallbackStatus?: string | null,
): ClusterNodeStatus[] {
  const safeReplicaCount = Math.max(0, Math.floor(replicaCount))
  const fallback = normalizeClusterNodeStatus(fallbackStatus ?? 'active')
  const primary = members.find(
    (member) => String(member.role ?? '').trim().toLowerCase() === 'primary',
  )
  const replicas = members.filter(
    (member) => String(member.role ?? '').trim().toLowerCase() !== 'primary',
  )

  const statuses: ClusterNodeStatus[] = [
    overlayScalingLifecycle(
      normalizeClusterNodeStatus(primary?.status ?? fallback),
      fallback,
    ),
  ]

  for (let index = 0; index < safeReplicaCount; index += 1) {
    const member = replicas[index]
    statuses.push(
      member
        ? overlayScalingLifecycle(
            normalizeClusterNodeStatus(member.status),
            fallback,
          )
        : 'provisioning',
    )
  }

  return statuses
}

/**
 * Preview cluster topology while editing replica count before save.
 * Keeps nodes that will be removed visible (marked `removing`) and marks
 * newly selected replicas as `adding` until the change is confirmed.
 * When `memberStatuses` is provided, existing nodes keep their live HA status
 * instead of inheriting the database lifecycle status.
 */
export function clusterReplicaChangePreview(
  databaseStatus: string | null | undefined,
  committedReplicaCount: number,
  draftReplicaCount: number,
  memberStatuses?: Array<ClusterNodeStatus | string | null | undefined> | null,
): {
  displayReplicaCount: number
  nodeStatuses: ClusterNodeStatus[]
} {
  const fallback = normalizeClusterNodeStatus(databaseStatus)
  const committed = Math.max(0, Math.floor(committedReplicaCount))
  const draft = Math.max(0, Math.floor(draftReplicaCount))
  const displayReplicaCount = Math.max(committed, draft)
  const liveStatuses =
    memberStatuses && memberStatuses.length > 0
      ? memberStatuses.map((status) => normalizeClusterNodeStatus(status))
      : null

  const resolveLiveStatus = (index: number): ClusterNodeStatus =>
    overlayScalingLifecycle(liveStatuses?.[index] ?? fallback, fallback)

  const nodeStatuses: ClusterNodeStatus[] = [resolveLiveStatus(0)]

  for (let index = 0; index < displayReplicaCount; index += 1) {
    const exists = index < committed
    const kept = index < draft
    if (exists && kept) {
      nodeStatuses.push(resolveLiveStatus(index + 1))
    } else if (!exists && kept) {
      nodeStatuses.push('adding')
    } else {
      nodeStatuses.push('removing')
    }
  }

  return { displayReplicaCount, nodeStatuses }
}

function clusterNodeStatusDotClass(status: ClusterNodeStatus): string {
  switch (status) {
    case 'active':
      return 'bg-emerald-500 dark:bg-emerald-400'
    case 'provisioning':
    case 'scaling':
    case 'starting':
    case 'pending':
    case 'adding':
      return 'bg-amber-500 dark:bg-amber-400'
    case 'removing':
      return 'bg-slate-400 dark:bg-slate-500'
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
    case 'scaling':
      return t('Scaling')
    case 'starting':
      return t('Starting')
    case 'failed':
      return t('Failed')
    case 'pending':
      return t('Pending')
    case 'adding':
      return t('Adding')
    case 'removing':
      return t('Removing')
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

/** Latest CPU/memory sample for a cluster node (null while loading or unavailable). */
export type DatabaseClusterNodeResourceMetrics = {
  cpu: number | null
  memory: number | null
}

type ClusterNodeMetrics =
  | { kind: 'resource'; cpu: number | null; memory: number | null }
  | { kind: 'connections'; current: number | null; max: number | null }

/**
 * Fixed-width percent slot sized to the widest value ("100%").
 * While loading, render a transparent "100%" so width/height never change.
 */
const RESOURCE_PERCENT_SLOT_CLASSNAME =
  'inline-block w-[4ch] shrink-0 text-end font-mono tabular-nums font-medium leading-none'

function ResourcePercentValue({ value }: { value: number | null }) {
  const ready = value != null && !Number.isNaN(value)
  return (
    <span
      className={cn(
        RESOURCE_PERCENT_SLOT_CLASSNAME,
        ready ? 'text-foreground' : 'text-transparent select-none',
      )}
      aria-hidden={!ready}
    >
      {ready ? `${Math.round(value)}%` : '100%'}
    </span>
  )
}

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
  const isPreviewChange = status === 'adding' || status === 'removing'
  // Scaling nodes stay available, so keep CPU/memory (or connections) visible.
  const showStatusBody =
    isPreviewChange ||
    status === 'provisioning' ||
    status === 'starting' ||
    status === 'pending' ||
    status === 'failed'

  const connectionsLabel =
    metrics.kind === 'connections'
      ? `${
          metrics.current == null ? '-' : metrics.current.toLocaleString()
        } / ${metrics.max == null ? '-' : metrics.max.toLocaleString()}`
      : null

  return (
    <div
      className={cn(
        'absolute select-none transition-opacity',
        status === 'removing' && 'opacity-45',
      )}
      style={{
        left: `${node.x}px`,
        top: `${node.y}px`,
        width: `${node.width}px`,
      }}
    >
      <div
        className={cn(
          'overflow-hidden rounded-md border bg-card shadow-sm',
          isPreviewChange
            ? 'border-dashed border-muted-foreground/55'
            : 'border-border',
          status === 'adding' && 'bg-amber-500/5',
          status === 'removing' && 'bg-muted/40',
        )}
      >
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
          <span
            className={cn(
              'min-w-0 flex-1 whitespace-nowrap text-[11px] font-medium text-foreground',
              status === 'removing' && 'line-through text-muted-foreground',
            )}
          >
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
          {showStatusBody ? (
            <span
              className={cn(
                'text-[10px] font-medium leading-none',
                status === 'removing'
                  ? 'text-muted-foreground'
                  : status === 'adding' ||
                      status === 'provisioning' ||
                      status === 'starting' ||
                      status === 'pending'
                    ? 'text-amber-700 dark:text-amber-400'
                    : 'text-muted-foreground',
              )}
            >
              {statusLabel}
            </span>
          ) : metrics.kind === 'connections' ? (
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
                <ResourcePercentValue value={metrics.cpu} />
              </span>
              <span
                className="h-3 w-px shrink-0 bg-border"
                aria-hidden
              />
              <span className="inline-flex items-center gap-1 text-[10px] leading-none">
                <span className="text-muted-foreground">{t('Memory')}</span>
                <ResourcePercentValue value={metrics.memory} />
              </span>
            </>
          )}
        </div>
      </div>
    </div>
  )
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
   * Latest CPU/memory per node (primary then replicas) from usage gauges.
   * When omitted, nodes show an unavailable placeholder instead of mock data.
   */
  nodeMetrics?: Array<DatabaseClusterNodeResourceMetrics | null | undefined>
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
  /** Shown in the interactive toolbar; refreshes live metrics and member status. */
  onRefresh?: () => void
  isRefreshing?: boolean
  /**
   * Override non-interactive preview height (defaults to
   * `DATABASE_CLUSTER_PREVIEW_HEIGHT`). Useful for marketing surfaces that
   * include a proxy node and need more vertical room.
   */
  previewHeight?: number
}

function resolveNodeResourceMetrics(
  nodeMetrics: Array<DatabaseClusterNodeResourceMetrics | null | undefined> | undefined,
  index: number,
): DatabaseClusterNodeResourceMetrics {
  return nodeMetrics?.[index] ?? { cpu: null, memory: null }
}

function ClusterDiagramNodes({
  layout,
  statuses,
  soloPrimary,
  proxy,
  nodeMetrics,
}: {
  layout: CompactLayout
  statuses: ClusterNodeStatus[]
  soloPrimary: boolean
  proxy?: DatabaseClusterProxy | null
  nodeMetrics?: Array<DatabaseClusterNodeResourceMetrics | null | undefined>
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
          metrics={{
            kind: 'resource',
            ...resolveNodeResourceMetrics(nodeMetrics, 0),
          }}
        />
        {layout.replicas.map((replica, index) => (
          <CompactClusterNode
            key={replica.id}
            label={replica.label}
            node={replica}
            status={statuses[index + 1] ?? 'active'}
            metrics={{
              kind: 'resource',
              ...resolveNodeResourceMetrics(nodeMetrics, index + 1),
            }}
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
  nodeMetrics,
  proxy,
  className,
  withSectionDivider = true,
  interactive = false,
  onRefresh,
  isRefreshing = false,
  previewHeight = DATABASE_CLUSTER_PREVIEW_HEIGHT,
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
          {onRefresh ? (
            <RefreshButton
              onClick={onRefresh}
              isRefreshing={isRefreshing}
              className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm text-foreground hover:bg-accent"
            />
          ) : null}

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
            nodeMetrics={nodeMetrics}
          />
        </div>
      </div>
    </div>
  ) : (
    <div
      className={cn('min-w-0', className)}
      style={{ height: previewHeight }}
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
              (previewHeight - 4) / layout.height,
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
            nodeMetrics={nodeMetrics}
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
