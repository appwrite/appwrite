import { SchemaBlueprintMat } from '@/components/global/shared/SchemaBlueprintMat'
import {
  SchemaVisualizerRelationshipEdges,
  SchemaVisualizerRelationshipEdgesMinimap,
} from '@/components/global/shared/SchemaVisualizerRelationshipEdges'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useViewportPanZoom } from '@/lib/hooks/useViewportPanZoom'
import { isHtmlDarkChrome } from '@/lib/html-theme'
import { formatMysqlColumnType } from '@/lib/mysql-table-ddl'
import { mysqlNav } from '@/lib/mysql-database-routes'
import {
  buildSchemaVisualizerRelationshipPaths,
  DEFAULT_SCHEMA_VISUALIZER_EDGE_LAYOUT,
  type SchemaVisualizerRelationshipPath,
} from '@/lib/schema-visualizer-relationship-paths'
import { layoutSchemaVisualizerNodesWithMetadata } from '@/lib/schema-visualizer-graph-layout'
import {
  useMysqlSchemaVisualizer,
  type MysqlVisualizerColumn,
  type MysqlVisualizerRelation,
} from '@/lib/react-query/hooks/mysql-databases'
import { getColumnIcon } from '@/lib/utils/column-icons'
import { cn } from '@/lib/utils'
import { useNavigate, useParams } from '@tanstack/react-router'
import {
  Check,
  ChevronDown,
  ChevronUp,
  Eye,
  Key,
  Link2,
  Loader2,
  Map as MapIcon,
  Maximize2,
  Table2,
  ZoomIn,
  ZoomOut,
} from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type RefObject } from 'react'
import { useMysqlSidebar } from './_components/MysqlSidebarContext'
import { useT } from '@/lib/i18n/translate'

type SchemaVisualizerProps = {
  databaseId: string
}

type RelationNode = {
  id: string
  schema: string
  name: string
  tableType: string
  isExternal: boolean
  x: number
  y: number
  width: number
  height: number
  /** Reserved layout height (>= visible height) for overlap detection and edge routing. */
  layoutHeight: number
  columns: MysqlVisualizerColumn[]
}

const NODE_WIDTH = 300
const NODE_HEADER_HEIGHT = DEFAULT_SCHEMA_VISUALIZER_EDGE_LAYOUT.nodeHeaderHeight
const COLUMN_HEIGHT = DEFAULT_SCHEMA_VISUALIZER_EDGE_LAYOUT.columnHeight
const NODE_PADDING = 12
const MAX_VISIBLE_COLUMNS = DEFAULT_SCHEMA_VISUALIZER_EDGE_LAYOUT.maxVisibleColumns
/** Gap between table columns reserved for relationship corridors. */
const ROUTING_CORRIDOR = 120
const MIN_NODE_GAP = ROUTING_CORRIDOR

function isViewRelation(relation: Pick<MysqlVisualizerRelation, 'tableType'>) {
  return relation.tableType.toUpperCase() === 'VIEW'
}

function formatRelationLabel(
  relation: Pick<MysqlVisualizerRelation, 'schema' | 'name' | 'isExternal'>,
) {
  if (relation.isExternal && relation.schema) {
    return `${relation.schema}.${relation.name}`
  }
  return relation.name
}

export function MysqlSchemaVisualizer({ databaseId }: SchemaVisualizerProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false }) as { projectId: string }
  const navigate = useNavigate()
  const { selectedSchema } = useMysqlSidebar()

  const {
    relations,
    relationships,
    totalRelations,
    loadedRelations,
    isLoading,
    isLoadingColumns,
    isComplete,
    error,
  } = useMysqlSchemaVisualizer(projectId, databaseId, selectedSchema)

  const minimapRef = useRef<HTMLDivElement>(null)

  const {
    canvasRef,
    zoom,
    pan,
    setZoom,
    setPan,
    isDragging,
    zoomInDisabled,
    zoomOutDisabled,
    bindCanvas,
    zoomIn,
    zoomOut,
    resetView,
    zoomPercentage,
  } = useViewportPanZoom()

  const [selectedRelation, setSelectedRelation] = useState<string | null>(null)
  const [expandedColumns, setExpandedColumns] = useState<Set<string>>(new Set())
  const [hasAutoFocused, setHasAutoFocused] = useState(false)
  const [showMinimap, setShowMinimap] = useState(true)
  const [copiedLink, setCopiedLink] = useState(false)

  const [isDarkMode, setIsDarkMode] = useState(() => {
    if (typeof window === 'undefined') return false
    return (
      isHtmlDarkChrome() ||
      window.matchMedia('(prefers-color-scheme: dark)').matches
    )
  })

  useEffect(() => {
    const checkDarkMode = () => {
      setIsDarkMode(
        isHtmlDarkChrome() ||
          window.matchMedia('(prefers-color-scheme: dark)').matches,
      )
    }

    checkDarkMode()

    const observer = new MutationObserver(checkDarkMode)
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class'],
    })

    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
    mediaQuery.addEventListener('change', checkDarkMode)

    return () => {
      observer.disconnect()
      mediaQuery.removeEventListener('change', checkDarkMode)
    }
  }, [])

  useEffect(() => {
    setHasAutoFocused(false)
    setSelectedRelation(null)
    setExpandedColumns(new Set())
  }, [selectedSchema, databaseId])

  const nodeDimensions = useMemo(
    () =>
      relations.map((relation) => {
        const columnCount = relation.columns.length
        const maxContentHeight =
          NODE_HEADER_HEIGHT +
          columnCount * COLUMN_HEIGHT +
          (columnCount > MAX_VISIBLE_COLUMNS ? COLUMN_HEIGHT : 0) +
          NODE_PADDING * 2 +
          16

        const visibleColumns = expandedColumns.has(relation.id)
          ? columnCount
          : Math.min(columnCount, MAX_VISIBLE_COLUMNS)

        const currentContentHeight =
          NODE_HEADER_HEIGHT +
          visibleColumns * COLUMN_HEIGHT +
          (columnCount > MAX_VISIBLE_COLUMNS && !expandedColumns.has(relation.id)
            ? COLUMN_HEIGHT
            : 0) +
          NODE_PADDING * 2 +
          16

        return {
          width: NODE_WIDTH,
          layoutHeight: Math.max(maxContentHeight, 100),
          renderHeight: Math.max(currentContentHeight, 100),
        }
      }),
    [relations, expandedColumns],
  )

  const layoutResult = useMemo(() => {
    if (relations.length === 0) {
      return {
        positions: new Map<string, { x: number; y: number }>(),
        layers: new Map<string, number>(),
        metrics: {
          startX: 100,
          startY: 100,
          columnGap: MIN_NODE_GAP,
          rowGap: 48,
          nodeWidth: NODE_WIDTH,
          columnStride: NODE_WIDTH + MIN_NODE_GAP,
          corridorXByBoundary: new Map(),
          layerStartX: new Map(),
        },
      }
    }

    const layoutNodes = relations.map((relation, index) => ({
      id: relation.id,
      width: nodeDimensions[index].width,
      height: nodeDimensions[index].layoutHeight,
      label: formatRelationLabel(relation),
    }))

    return layoutSchemaVisualizerNodesWithMetadata(
      layoutNodes,
      relationships.map((relationship) => ({
        from: relationship.from,
        to: relationship.to,
      })),
      {
        startX: 100,
        startY: 100,
        columnGap: MIN_NODE_GAP,
        rowGap: 56,
        innerGap: 32,
        maxNodesPerRow: 2,
        componentGap: MIN_NODE_GAP * 2,
      },
    )
  }, [relations, relationships, nodeDimensions])

  const nodes = useMemo<RelationNode[]>(() => {
    if (relations.length === 0) return []

    return relations.map((relation, index) => {
      const { width, layoutHeight, renderHeight } = nodeDimensions[index]
      const position = layoutResult.positions.get(relation.id) ?? { x: 100, y: 100 }

      return {
        id: relation.id,
        schema: relation.schema,
        name: relation.name,
        tableType: relation.tableType,
        isExternal: relation.isExternal,
        x: position.x,
        y: position.y,
        width,
        height: renderHeight,
        layoutHeight,
        columns: relation.columns,
      }
    })
  }, [relations, nodeDimensions, layoutResult.positions])

  const visibleRelationships = useMemo(() => {
    const nodeIds = new Set(nodes.map((node) => node.id))
    return relationships.filter(
      (relationship) =>
        nodeIds.has(relationship.from) && nodeIds.has(relationship.to),
    )
  }, [nodes, relationships])

  const relationshipPaths = useMemo(
    () =>
      buildSchemaVisualizerRelationshipPaths(
        nodes.map((node) => ({
          id: node.id,
          x: node.x,
          y: node.y,
          width: node.width,
          height: Math.max(node.height, node.layoutHeight),
          columns: node.columns,
        })),
        visibleRelationships,
        expandedColumns,
        DEFAULT_SCHEMA_VISUALIZER_EDGE_LAYOUT,
        layoutResult.metrics,
        layoutResult.layers,
      ),
    [nodes, visibleRelationships, expandedColumns, layoutResult.metrics, layoutResult.layers],
  )

  const edgeCanvasExtent = useMemo(() => {
    const padding = 120
    if (nodes.length === 0) return { width: 1, height: 1 }

    const maxX = Math.max(...nodes.map((node) => node.x + node.width)) + padding
    const maxY = Math.max(...nodes.map((node) => node.y + node.height)) + padding

    return {
      width: Math.max(maxX, 1),
      height: Math.max(maxY, 1),
    }
  }, [nodes])

  useEffect(() => {
    if (nodes.length === 0 || hasAutoFocused || isLoading) return

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

    if (bounds.minX === Infinity || !canvasRef.current) return

    const canvas = canvasRef.current
    const canvasWidth = canvas.clientWidth
    const canvasHeight = canvas.clientHeight
    const contentWidth = bounds.maxX - bounds.minX
    const contentHeight = bounds.maxY - bounds.minY
    const padding = 100
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
    setHasAutoFocused(true)
  }, [nodes, isLoading, hasAutoFocused, canvasRef, setPan, setZoom])

  const toggleColumns = (relationId: string) => {
    setExpandedColumns((previous) => {
      const next = new Set(previous)
      if (next.has(relationId)) {
        next.delete(relationId)
      } else {
        next.add(relationId)
      }
      return next
    })
  }

  const handleCopyShareLink = async () => {
    try {
      await navigator.clipboard.writeText(window.location.href)
      setCopiedLink(true)
      window.setTimeout(() => setCopiedLink(false), 2000)
    } catch {
      /* ignore clipboard errors */
    }
  }

  const handleNavigateToRows = (relationId: string) => {
    navigate(mysqlNav({ projectId, databaseId }).table({ tableId: relationId }).rows())
  }

  const handleNavigateToColumns = (relationId: string) => {
    navigate(
      mysqlNav({ projectId, databaseId }).table({ tableId: relationId }).columns(),
    )
  }

  const loadingLabel = useMemo(() => {
    if (!selectedSchema) return t('Select a schema to visualize')
    if (totalRelations > 0) {
      return `${t('Loading schema')} (${loadedRelations}/${totalRelations})`
    }
    return t('Loading schema...')
  }, [loadedRelations, selectedSchema, totalRelations, t])

  const minimapBounds = useMemo(() => {
    if (nodes.length === 0) return null

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

    if (bounds.minX === Infinity) return null

    const padding = 100
    return {
      minX: bounds.minX - padding,
      minY: bounds.minY - padding,
      maxX: bounds.maxX + padding,
      maxY: bounds.maxY + padding,
      width: bounds.maxX - bounds.minX + padding * 2,
      height: bounds.maxY - bounds.minY + padding * 2,
    }
  }, [nodes])

  const viewportRect = useMemo(() => {
    if (!canvasRef.current || !minimapBounds) return null

    const canvas = canvasRef.current
    const canvasWidth = canvas.clientWidth
    const canvasHeight = canvas.clientHeight
    const viewportLeft = -pan.x / zoom
    const viewportTop = -pan.y / zoom
    const viewportRight = viewportLeft + canvasWidth / zoom
    const viewportBottom = viewportTop + canvasHeight / zoom

    return {
      left: viewportLeft,
      top: viewportTop,
      width: viewportRight - viewportLeft,
      height: viewportBottom - viewportTop,
    }
  }, [pan, zoom, minimapBounds, canvasRef])

  const handleMinimapClick = (nodeId: string) => {
    const node = nodes.find((item) => item.id === nodeId)
    if (!node || !canvasRef.current) return

    const canvas = canvasRef.current
    const centerX = node.x + node.width / 2
    const centerY = node.y + node.height / 2

    setPan({
      x: canvas.clientWidth / 2 - centerX * zoom,
      y: canvas.clientHeight / 2 - centerY * zoom,
    })
    setSelectedRelation(nodeId)
  }

  const toolbar = (
    <VisualizerToolbar
      copiedLink={copiedLink}
      onCopyLink={handleCopyShareLink}
      zoomPercentage={zoomPercentage}
      zoomIn={zoomIn}
      zoomOut={zoomOut}
      resetView={resetView}
      zoomInDisabled={zoomInDisabled}
      zoomOutDisabled={zoomOutDisabled}
      loadingLabel={loadingLabel}
      showProgress={Boolean(selectedSchema && (!isComplete || isLoadingColumns))}
    />
  )

  if (!selectedSchema) {
    return (
      <div className="flex h-full flex-col">
        {toolbar}
        <div className="flex flex-1 items-center justify-center px-6 text-center text-[13px] text-muted-foreground">
          {t('Select a schema in the sidebar to explore tables, views, and relationships.')}
        </div>
      </div>
    )
  }

  if (error && nodes.length === 0) {
    return (
      <div className="flex h-full flex-col">
        {toolbar}
        <div className="flex flex-1 items-center justify-center px-6 text-center text-[13px] text-destructive">
          {error.message || t('Failed to load schema visualizer')}
        </div>
      </div>
    )
  }

  if (isLoading && nodes.length === 0) {
    return (
      <div className="flex h-full flex-col">
        {toolbar}
        <div className="flex flex-1 flex-col items-center justify-center gap-3 text-[13px] text-muted-foreground">
          <Loader2 className="h-5 w-5 animate-spin" />
          <span>{loadingLabel}</span>
        </div>
      </div>
    )
  }

  if (nodes.length === 0 && isComplete) {
    return (
      <div className="flex h-full flex-col">
        {toolbar}
        <EmptySchemaState schema={selectedSchema} />
      </div>
    )
  }

  return (
    <div className="relative flex h-full min-h-0 flex-col overflow-hidden">
      {toolbar}

      <div
        className="relative min-h-0 flex-1 overflow-hidden"
        style={{ backgroundColor: 'hsl(var(--muted) / 0.3)' }}
      >
        <div
          ref={canvasRef}
          className={cn(
            'h-full w-full cursor-grab overflow-hidden select-none',
            isDragging && 'cursor-grabbing',
          )}
          {...bindCanvas}
        >
          <div
            className="relative h-full w-full"
            style={{
              transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`,
              transformOrigin: '0 0',
            }}
          >
            <SchemaBlueprintMat />
            <SchemaVisualizerRelationshipEdges
              paths={relationshipPaths}
              extent={edgeCanvasExtent}
            />

            <div className="relative z-20">
              {nodes.map((node) => (
                <RelationNodeCard
                  key={node.id}
                  node={node}
                  selected={selectedRelation === node.id}
                  expanded={expandedColumns.has(node.id)}
                  onSelect={() =>
                    setSelectedRelation(
                      node.id === selectedRelation ? null : node.id,
                    )
                  }
                  onToggleColumns={() => toggleColumns(node.id)}
                  onNavigateRows={() => handleNavigateToRows(node.id)}
                  onNavigateColumns={() => handleNavigateToColumns(node.id)}
                />
              ))}
            </div>
          </div>
        </div>

        {showMinimap && nodes.length > 0 && minimapBounds ? (
          <Minimap
            minimapRef={minimapRef}
            nodes={nodes}
            relationshipPaths={relationshipPaths}
            selectedRelation={selectedRelation}
            minimapBounds={minimapBounds}
            viewportRect={viewportRect}
            isDarkMode={isDarkMode}
            onMinimapClick={handleMinimapClick}
            onClose={() => setShowMinimap(false)}
          />
        ) : null}

        {!showMinimap && nodes.length > 0 ? (
          <Button
            variant="outline"
            size="sm"
            className="absolute bottom-4 end-4 z-10 h-8 w-8 p-0 bg-card/95 backdrop-blur-sm"
            onClick={() => setShowMinimap(true)}
          >
            <MapIcon className="h-4 w-4" />
          </Button>
        ) : null}
      </div>
    </div>
  )
}

type VisualizerToolbarProps = {
  copiedLink: boolean
  onCopyLink: () => void
  zoomPercentage: number
  zoomIn: () => void
  zoomOut: () => void
  resetView: () => void
  zoomInDisabled: boolean
  zoomOutDisabled: boolean
  loadingLabel: string
  showProgress: boolean
}

function VisualizerToolbar({
  copiedLink,
  onCopyLink,
  zoomPercentage,
  zoomIn,
  zoomOut,
  resetView,
  zoomInDisabled,
  zoomOutDisabled,
  loadingLabel,
  showProgress,
}: VisualizerToolbarProps) {
  const t = useT()

  return (
    <div className="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-background px-4 py-2">
      {showProgress ? (
        <span className="truncate text-[12px] text-muted-foreground">
          {loadingLabel}
        </span>
      ) : (
        <span />
      )}

      <div className="flex items-center gap-2">
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              className="h-8 w-8 p-0"
              onClick={onCopyLink}
            >
              {copiedLink ? (
                <Check className="h-4 w-4" />
              ) : (
                <Link2 className="h-4 w-4" />
              )}
            </Button>
          </TooltipTrigger>
          <TooltipContent>
            {copiedLink ? t('Link copied') : t('Copy link')}
          </TooltipContent>
        </Tooltip>

        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              className="h-8 w-8 p-0"
              onClick={zoomIn}
              disabled={zoomInDisabled}
            >
              <ZoomIn className="h-4 w-4" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>{t('Zoom in')}</TooltipContent>
        </Tooltip>
        <div className="flex h-8 min-w-[64px] items-center justify-center rounded-md border border-border px-3">
          <span className="text-[12px] font-medium">{zoomPercentage}%</span>
        </div>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              className="h-8 w-8 p-0"
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
              variant="outline"
              size="sm"
              className="h-8 w-8 p-0"
              onClick={resetView}
            >
              <Maximize2 className="h-4 w-4" />
            </Button>
          </TooltipTrigger>
          <TooltipContent>{t('Fit to view')}</TooltipContent>
        </Tooltip>
      </div>
    </div>
  )
}

type RelationNodeCardProps = {
  node: RelationNode
  selected: boolean
  expanded: boolean
  onSelect: () => void
  onToggleColumns: () => void
  onNavigateRows: () => void
  onNavigateColumns: () => void
}

function RelationNodeCard({
  node,
  selected,
  expanded,
  onSelect,
  onToggleColumns,
  onNavigateRows,
  onNavigateColumns,
}: RelationNodeCardProps) {
  const t = useT()
  const isView = isViewRelation(node)
  const label = formatRelationLabel(node)
  const visibleColumns = expanded
    ? node.columns
    : node.columns.slice(0, MAX_VISIBLE_COLUMNS)

  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>
        <div
          className="absolute"
          style={{
            left: `${node.x}px`,
            top: `${node.y}px`,
            width: `${node.width}px`,
          }}
        >
          <div
            className={cn(
              'overflow-hidden rounded-lg border bg-card transition-all cursor-pointer select-none',
              selected && 'ring-2 ring-ring',
              node.isExternal && 'border-dashed',
            )}
            onClick={onSelect}
          >
            <div className="flex items-center gap-2 border-b border-border bg-muted/50 px-3 py-2">
              {isView ? (
                <Eye className="h-4 w-4 shrink-0 text-muted-foreground" />
              ) : (
                <Table2 className="h-4 w-4 shrink-0 text-muted-foreground" />
              )}
              <span className="truncate text-[13px] font-medium text-foreground">
                {label}
              </span>
              {isView ? (
                <Badge variant="info" className="ms-auto text-[10px] shrink-0">
                  {t('View')}
                </Badge>
              ) : null}
              {node.isExternal ? (
                <Badge variant="warning" className="ms-auto text-[10px] shrink-0">
                  {t('External')}
                </Badge>
              ) : null}
            </div>

            <div className="p-2">
              {node.columns.length > 0 ? (
                <div className="space-y-0.5">
                  {visibleColumns.map((column) => {
                    const Icon = getColumnIcon(column.udtName || column.dataType)
                    const typeLabel = formatMysqlColumnType({
                      data_type: column.dataType,
                      udt_name: column.udtName,
                    })

                    return (
                      <div
                        key={column.name}
                        className="flex items-center gap-2 rounded px-2 py-1 text-[12px] hover:bg-muted/50"
                      >
                        <Icon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                        <span className="flex flex-1 items-center gap-1.5 truncate text-foreground">
                          {column.name}
                          {column.isPrimaryKey ? (
                            <Key className="h-3 w-3 shrink-0 text-muted-foreground" />
                          ) : null}
                        </span>
                        <div className="flex shrink-0 items-center gap-1.5">
                          {column.required ? (
                            <Badge
                              variant="outline"
                              className="h-4 px-1.5 text-[10px] font-normal"
                            >
                              {t('Required')}
                            </Badge>
                          ) : null}
                          <Badge
                            variant="outline"
                            className="h-4 px-1.5 text-[10px] font-normal"
                          >
                            {typeLabel}
                          </Badge>
                        </div>
                      </div>
                    )
                  })}
                  {node.columns.length > MAX_VISIBLE_COLUMNS ? (
                    <button
                      type="button"
                      onClick={(event) => {
                        event.stopPropagation()
                        onToggleColumns()
                      }}
                      className="flex w-full items-center justify-center gap-1.5 rounded px-2 py-1.5 text-[11px] text-muted-foreground transition-colors hover:bg-muted/50"
                    >
                      {expanded ? (
                        <>
                          <ChevronUp className="h-3 w-3" />
                          {t('Show less')}
                        </>
                      ) : (
                        <>
                          <ChevronDown className="h-3 w-3" />
                          Show {node.columns.length - MAX_VISIBLE_COLUMNS} more
                        </>
                      )}
                    </button>
                  ) : null}
                </div>
              ) : (
                <div className="px-2 py-3 text-[12px] text-muted-foreground">
                  {t('No columns loaded')}
                </div>
              )}
            </div>
          </div>
        </div>
      </ContextMenuTrigger>
      <ContextMenuContent>
        {!node.isExternal ? (
          <>
            <ContextMenuItem onClick={onNavigateRows}>
              <Eye className="me-2 h-4 w-4" />
              {t('Rows')}
            </ContextMenuItem>
            <ContextMenuItem onClick={onNavigateColumns}>
              <Table2 className="me-2 h-4 w-4" />
              {t('Columns')}
            </ContextMenuItem>
          </>
        ) : null}
      </ContextMenuContent>
    </ContextMenu>
  )
}

function EmptySchemaState({ schema }: { schema: string }) {
  const t = useT()
  return (
    <div
      className="relative flex flex-1 items-center justify-center overflow-hidden"
      style={{ backgroundColor: 'hsl(var(--muted) / 0.3)' }}
    >
      <SchemaBlueprintMat />
      <div className="relative z-10 max-w-lg px-6 text-center">
        <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-card ring-2 ring-border">
          <Table2 className="h-6 w-6 text-foreground" />
        </div>
        <h3 className="mb-2 text-base font-semibold text-foreground">
          No tables or views in {schema}
        </h3>
        <p className="text-sm leading-relaxed text-muted-foreground">
          {t('This schema has no user tables or views to visualize yet.')}
        </p>
      </div>
    </div>
  )
}

type MinimapProps = {
  minimapRef: RefObject<HTMLDivElement | null>
  nodes: RelationNode[]
  relationshipPaths: SchemaVisualizerRelationshipPath[]
  selectedRelation: string | null
  minimapBounds: {
    minX: number
    minY: number
    width: number
    height: number
  }
  viewportRect: {
    left: number
    top: number
    width: number
    height: number
  } | null
  isDarkMode: boolean
  onMinimapClick: (nodeId: string) => void
  onClose: () => void
}

function Minimap({
  minimapRef,
  nodes,
  relationshipPaths,
  selectedRelation,
  minimapBounds,
  viewportRect,
  isDarkMode,
  onMinimapClick,
  onClose,
}: MinimapProps) {
  const t = useT()
  return (
    <div className="absolute bottom-4 end-4 z-10 h-44 w-64 overflow-hidden rounded-lg border border-border bg-card/95 backdrop-blur-sm select-none">
      <div className="absolute start-0 end-0 top-0 flex h-8 items-center justify-between border-b border-border bg-muted/50 px-3">
        <span className="flex items-center gap-2 text-[12px] font-medium text-foreground">
          <MapIcon className="h-4 w-4" />
          {t('Overview')}
        </span>
        <button
          type="button"
          onClick={onClose}
          className="text-[14px] leading-none text-muted-foreground hover:text-foreground"
        >
          ×
        </button>
      </div>
      <div
        ref={minimapRef}
        className={cn(
          'relative mt-8 h-[calc(100%-32px)] w-full border',
          isDarkMode ? 'bg-muted/80 border-border/60' : 'bg-[#e5e5e5] border-border',
        )}
      >
        <svg
          className="absolute inset-0 h-full w-full"
          viewBox={`${minimapBounds.minX} ${minimapBounds.minY} ${minimapBounds.width} ${minimapBounds.height}`}
          preserveAspectRatio="xMidYMid meet"
        >
          <SchemaVisualizerRelationshipEdgesMinimap
            paths={relationshipPaths}
            isDarkMode={isDarkMode}
          />

          {nodes.map((node) => {
            const isSelected = selectedRelation === node.id
            const label = formatRelationLabel(node)
            return (
              <g key={`minimap-node-${node.id}`}>
                <rect
                  x={node.x}
                  y={node.y}
                  width={node.width}
                  height={node.height}
                  rx="6"
                  ry="6"
                  fill={
                    isSelected
                      ? '#FD366E'
                      : isDarkMode
                        ? 'oklch(0.2 0.006 286)'
                        : 'oklch(1 0 0)'
                  }
                  stroke={
                    isSelected
                      ? '#FD366E'
                      : isDarkMode
                        ? 'oklch(0.5 0.01 286)'
                        : 'oklch(0.4 0.01 285)'
                  }
                  strokeWidth={isSelected ? 3 : 2}
                  className="cursor-pointer"
                  onClick={() => onMinimapClick(node.id)}
                />
                <text
                  x={node.x + 4}
                  y={node.y + 12}
                  fontSize="8"
                  fill={
                    isSelected
                      ? '#ffffff'
                      : isDarkMode
                        ? 'oklch(0.985 0 0)'
                        : 'oklch(0.141 0.005 285.823)'
                  }
                  fontWeight="600"
                  className="pointer-events-none"
                >
                  {label.length > 15 ? `${label.slice(0, 15)}...` : label}
                </text>
              </g>
            )
          })}

          {viewportRect ? (
            <rect
              x={viewportRect.left}
              y={viewportRect.top}
              width={viewportRect.width}
              height={viewportRect.height}
              fill="none"
              stroke={isDarkMode ? 'oklch(0.985 0 0)' : 'oklch(0.141 0.005 285.823)'}
              strokeWidth="2"
              strokeDasharray="4 4"
              opacity="0.6"
            />
          ) : null}
        </svg>
      </div>
    </div>
  )
}
