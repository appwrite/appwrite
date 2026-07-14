import { useMemo } from 'react'
import { Database } from 'lucide-react'
import { SchemaBlueprintMat } from '@/components/global/shared/SchemaBlueprintMat'
import { SchemaVisualizerRelationshipEdges } from '@/components/global/shared/SchemaVisualizerRelationshipEdges'
import type { SchemaVisualizerRelationshipPath } from '@/lib/schema-visualizer-relationship-paths'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

/** Mid-card cluster area; taller so node headers + live metric placeholders stay readable. */
export const DATABASE_CLUSTER_PREVIEW_HEIGHT = 200
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
  primary: DiagramNode
  replicas: DiagramNode[]
  paths: SchemaVisualizerRelationshipPath[]
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

function buildCompactLayout(
  primaryLabel: string,
  replicaLabels: string[],
): CompactLayout {
  const isSolo = replicaLabels.length === 0
  const primaryWidth = nodeWidthForLabel(primaryLabel)
  const replicaWidths = replicaLabels.map((label) => nodeWidthForLabel(label))
  const replicaRowWidth =
    replicaWidths.length > 0
      ? replicaWidths.reduce((sum, width) => sum + width, 0) +
        Math.max(0, replicaWidths.length - 1) * HORIZONTAL_GAP
      : 0
  const clusterWidth = Math.max(primaryWidth, replicaRowWidth)
  const clusterHeight = isSolo
    ? NODE_HEIGHT
    : NODE_HEIGHT + VERTICAL_GAP + NODE_HEIGHT

  const width = clusterWidth + CONTENT_PADDING_X * 2
  const height = clusterHeight + CONTENT_PADDING_Y * 2

  const clusterStartX = CONTENT_PADDING_X
  const clusterStartY = CONTENT_PADDING_Y

  const primary: DiagramNode = {
    id: 'primary',
    label: primaryLabel,
    x: clusterStartX + clusterWidth / 2 - primaryWidth / 2,
    y: clusterStartY,
    width: primaryWidth,
    height: NODE_HEIGHT,
  }

  const replicaY = primary.y + NODE_HEIGHT + VERTICAL_GAP
  const replicaStartX = width / 2 - replicaRowWidth / 2
  let replicaX = replicaStartX
  const replicas = replicaLabels.map((label, index) => {
    const nodeWidth = replicaWidths[index]!
    const node: DiagramNode = {
      id: `replica-${index + 1}`,
      label,
      x: replicaX,
      y: replicaY,
      width: nodeWidth,
      height: NODE_HEIGHT,
    }
    replicaX += nodeWidth + HORIZONTAL_GAP
    return node
  })

  return {
    width,
    height,
    primary,
    replicas,
    paths: buildCompactPaths(primary, replicas),
  }
}

function CompactClusterNode({
  label,
  node,
  status,
  emphasized,
  metrics,
}: {
  label: string
  node: DiagramNode
  status: ClusterNodeStatus
  emphasized?: boolean
  metrics: { cpu: number; memory: number }
}) {
  const t = useT()
  const statusLabel = clusterNodeStatusLabel(status, t)

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
          <Database
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
  const mocks = [24, 18, 12, 31, 9]
  return mocks[Math.abs(seed) % mocks.length]!
}

type DatabaseClusterPreviewProps = {
  replicaCount: number
  /**
   * Per-node status for primary then each replica.
   * Falls back to `active` when omitted or shorter than the node list.
   */
  nodeStatuses?: Array<ClusterNodeStatus | string | null | undefined>
  className?: string
  /** When true, wrap with the same card section divider as project request charts. */
  withSectionDivider?: boolean
}

/**
 * Compact, non-interactive cluster topology for database resource cards.
 * Same visual language as {@link PostgresHaClusterDiagram}, sized for org-style card mid-sections.
 */
export function DatabaseClusterPreview({
  replicaCount,
  nodeStatuses,
  className,
  withSectionDivider = true,
}: DatabaseClusterPreviewProps) {
  const t = useT()
  const safeReplicaCount = Math.max(0, Math.floor(replicaCount))
  const primaryLabel = t('Primary')
  const replicaLabels = useMemo(
    () =>
      Array.from(
        { length: safeReplicaCount },
        (_, index) => `${t('Replica')} ${index + 1}`,
      ),
    [safeReplicaCount, t],
  )
  const layout = useMemo(
    () => buildCompactLayout(primaryLabel, replicaLabels),
    [primaryLabel, replicaLabels],
  )
  const resolvedStatuses = useMemo(() => {
    const total = 1 + safeReplicaCount
    return Array.from({ length: total }, (_, index) =>
      normalizeClusterNodeStatus(nodeStatuses?.[index] ?? 'active'),
    )
  }, [nodeStatuses, safeReplicaCount])

  const ariaLabel =
    safeReplicaCount === 0
      ? t('Primary')
      : `${t('Primary')} + ${safeReplicaCount} ${
          safeReplicaCount === 1 ? t('Replica') : t('Replicas')
        }`

  const content = (
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
          <SchemaBlueprintMat density="dense" />
          <SchemaVisualizerRelationshipEdges
            paths={layout.paths}
            extent={{ width: layout.width, height: layout.height }}
            showArrowHeads={false}
          />
          <div className="relative z-20">
            <CompactClusterNode
              label={layout.primary.label}
              node={layout.primary}
              status={resolvedStatuses[0] ?? 'active'}
              emphasized={safeReplicaCount === 0}
              metrics={mockNodeMetrics(0)}
            />
            {layout.replicas.map((replica, index) => (
              <CompactClusterNode
                key={replica.id}
                label={replica.label}
                node={replica}
                status={resolvedStatuses[index + 1] ?? 'active'}
                metrics={mockNodeMetrics(index + 1)}
              />
            ))}
          </div>
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
