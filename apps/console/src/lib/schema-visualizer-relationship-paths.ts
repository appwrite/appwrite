import type { SchemaVisualizerLayoutMetrics } from '@/lib/schema-visualizer-graph-layout'

export type SchemaVisualizerEdgeNode = {
  id: string
  x: number
  y: number
  width: number
  height: number
  columns: Array<{ name: string }>
}

export type SchemaVisualizerEdgeLayout = {
  nodeHeaderHeight: number
  columnHeight: number
  bodyPadding: number
  maxVisibleColumns: number
  edgeStubGap: number
  pathLaneSpacing: number
}

export const DEFAULT_SCHEMA_VISUALIZER_EDGE_LAYOUT: SchemaVisualizerEdgeLayout = {
  nodeHeaderHeight: 40,
  columnHeight: 28,
  bodyPadding: 8,
  maxVisibleColumns: 20,
  edgeStubGap: 16,
  pathLaneSpacing: 10,
}

export type SchemaVisualizerEdgeEndpoint = {
  x: number
  y: number
  side: 'left' | 'right'
}

export type SchemaVisualizerRelationshipPath = {
  d: string
  from: SchemaVisualizerEdgeEndpoint
  to: SchemaVisualizerEdgeEndpoint
}

type EdgeRect = {
  id: string
  x: number
  y: number
  width: number
  height: number
}

type PathSegment =
  | { kind: 'horizontal'; y: number; x1: number; x2: number }
  | { kind: 'vertical'; x: number; y1: number; y2: number }

function getColumnRowIndex(
  node: SchemaVisualizerEdgeNode,
  columnName: string,
  expanded: boolean,
  maxVisibleColumns: number,
): number {
  const colIndex = node.columns.findIndex((column) => column.name === columnName)
  if (colIndex === -1) return 0

  const visibleLimit = expanded
    ? node.columns.length
    : Math.min(node.columns.length, maxVisibleColumns)

  if (colIndex < visibleLimit) return colIndex
  return Math.max(0, visibleLimit - 1)
}

function getColumnAnchorY(
  node: SchemaVisualizerEdgeNode,
  columnName: string,
  expanded: boolean,
  layout: SchemaVisualizerEdgeLayout,
): number {
  const rowIndex = getColumnRowIndex(
    node,
    columnName,
    expanded,
    layout.maxVisibleColumns,
  )
  const contentTop = node.y + layout.nodeHeaderHeight + layout.bodyPadding
  return contentTop + rowIndex * layout.columnHeight + layout.columnHeight / 2
}

function nodeToRect(node: SchemaVisualizerEdgeNode, clearance: number): EdgeRect {
  return {
    id: node.id,
    x: node.x - clearance,
    y: node.y - clearance,
    width: node.width + clearance * 2,
    height: node.height + clearance * 2,
  }
}

function verticalSegmentHitsRect(
  x: number,
  y1: number,
  y2: number,
  rect: EdgeRect,
): boolean {
  const minY = Math.min(y1, y2)
  const maxY = Math.max(y1, y2)
  return (
    x >= rect.x &&
    x <= rect.x + rect.width &&
    maxY >= rect.y &&
    minY <= rect.y + rect.height
  )
}

function horizontalSegmentHitsRect(
  y: number,
  x1: number,
  x2: number,
  rect: EdgeRect,
): boolean {
  const minX = Math.min(x1, x2)
  const maxX = Math.max(x1, x2)
  return (
    y >= rect.y &&
    y <= rect.y + rect.height &&
    maxX >= rect.x &&
    minX <= rect.x + rect.width
  )
}

function verticalSegmentClear(
  x: number,
  y1: number,
  y2: number,
  obstacles: EdgeRect[],
  ignoreIds: Set<string>,
): boolean {
  return !obstacles.some(
    (rect) =>
      !ignoreIds.has(rect.id) && verticalSegmentHitsRect(x, y1, y2, rect),
  )
}

function horizontalSegmentClear(
  y: number,
  x1: number,
  x2: number,
  obstacles: EdgeRect[],
  ignoreIds: Set<string>,
): boolean {
  return !obstacles.some(
    (rect) =>
      !ignoreIds.has(rect.id) && horizontalSegmentHitsRect(y, x1, x2, rect),
  )
}

function pathSegmentToRect(segment: PathSegment, padding: number): EdgeRect {
  if (segment.kind === 'horizontal') {
    const minX = Math.min(segment.x1, segment.x2)
    const maxX = Math.max(segment.x1, segment.x2)
    return {
      id: 'path',
      x: minX - padding,
      y: segment.y - padding,
      width: maxX - minX + padding * 2,
      height: padding * 2,
    }
  }

  const minY = Math.min(segment.y1, segment.y2)
  const maxY = Math.max(segment.y1, segment.y2)
  return {
    id: 'path',
    x: segment.x - padding,
    y: minY - padding,
    width: padding * 2,
    height: maxY - minY + padding * 2,
  }
}

function corridorXForBoundary(
  boundaryLayer: number,
  metrics: SchemaVisualizerLayoutMetrics,
  laneOffset: number,
): number {
  const mapped = metrics.corridorXByBoundary.get(boundaryLayer)
  if (mapped != null) {
    return mapped + laneOffset
  }

  return (
    metrics.startX +
    boundaryLayer * metrics.columnStride +
    metrics.nodeWidth +
    metrics.columnGap / 2 +
    laneOffset
  )
}

function buildCorridorPath(
  from: SchemaVisualizerEdgeEndpoint,
  to: SchemaVisualizerEdgeEndpoint,
  fromLayer: number,
  toLayer: number,
  metrics: SchemaVisualizerLayoutMetrics,
  laneOffset: number,
  obstacles: EdgeRect[],
  ignoreIds: Set<string>,
): { d: string; segments: PathSegment[] } | null {
  const { x: fx, y: fy } = from
  const { x: tx, y: ty } = to

  const minLayer = Math.min(fromLayer, toLayer)
  const maxLayer = Math.max(fromLayer, toLayer)

  const corridorCandidates: number[] = []
  for (let boundary = minLayer; boundary < maxLayer; boundary += 1) {
    corridorCandidates.push(corridorXForBoundary(boundary, metrics, laneOffset))
  }

  corridorCandidates.push(corridorXForBoundary(maxLayer, metrics, laneOffset))

  for (const corridorX of corridorCandidates) {
    const leftX = Math.min(fx, tx, corridorX)
    const rightX = Math.max(fx, tx, corridorX)

    if (
      verticalSegmentClear(corridorX, fy, ty, obstacles, ignoreIds) &&
      horizontalSegmentClear(fy, fx, corridorX, obstacles, ignoreIds) &&
      horizontalSegmentClear(ty, corridorX, tx, obstacles, ignoreIds)
    ) {
      return {
        d: `M ${fx} ${fy} H ${corridorX} V ${ty} H ${tx}`,
        segments: [
          { kind: 'horizontal', y: fy, x1: fx, x2: corridorX },
          { kind: 'vertical', x: corridorX, y1: fy, y2: ty },
          { kind: 'horizontal', y: ty, x1: corridorX, x2: tx },
        ],
      }
    }

    const routeY = (fy + ty) / 2
    if (
      horizontalSegmentClear(routeY, leftX, rightX, obstacles, ignoreIds) &&
      horizontalSegmentClear(fy, fx, corridorX, obstacles, ignoreIds) &&
      horizontalSegmentClear(ty, corridorX, tx, obstacles, ignoreIds)
    ) {
      return {
        d: `M ${fx} ${fy} H ${corridorX} V ${routeY} H ${tx} V ${ty}`,
        segments: [
          { kind: 'horizontal', y: fy, x1: fx, x2: corridorX },
          { kind: 'vertical', x: corridorX, y1: fy, y2: routeY },
          { kind: 'horizontal', y: routeY, x1: corridorX, x2: tx },
          { kind: 'vertical', x: tx, y1: routeY, y2: ty },
        ],
      }
    }
  }

  return null
}

function buildSameLayerPath(
  from: SchemaVisualizerEdgeEndpoint,
  to: SchemaVisualizerEdgeEndpoint,
  fromNode: SchemaVisualizerEdgeNode,
  toNode: SchemaVisualizerEdgeNode,
  laneOffset: number,
  obstacles: EdgeRect[],
  ignoreIds: Set<string>,
): { d: string; segments: PathSegment[] } {
  const { x: fx, y: fy } = from
  const { x: tx, y: ty } = to
  const minY = Math.min(fromNode.y, toNode.y) - 32 + laneOffset
  const maxY =
    Math.max(fromNode.y + fromNode.height, toNode.y + toNode.height) + 32 + laneOffset

  const routeAbove = minY
  const routeBelow = maxY

  const useAbove = Math.abs(routeAbove - fy) + Math.abs(routeAbove - ty)
    <= Math.abs(routeBelow - fy) + Math.abs(routeBelow - ty)

  const routeY = useAbove ? routeAbove : routeBelow
  const stubX =
    from.side === 'right'
      ? Math.max(fx, tx) + DEFAULT_SCHEMA_VISUALIZER_EDGE_LAYOUT.edgeStubGap
      : Math.min(fx, tx) - DEFAULT_SCHEMA_VISUALIZER_EDGE_LAYOUT.edgeStubGap

  if (
    horizontalSegmentClear(routeY, Math.min(stubX, fx, tx), Math.max(stubX, fx, tx), obstacles, ignoreIds)
  ) {
    return {
      d: `M ${fx} ${fy} H ${stubX} V ${routeY} H ${tx} V ${ty}`,
      segments: [
        { kind: 'horizontal', y: fy, x1: fx, x2: stubX },
        { kind: 'vertical', x: stubX, y1: fy, y2: routeY },
        { kind: 'horizontal', y: routeY, x1: stubX, x2: tx },
        { kind: 'vertical', x: tx, y1: routeY, y2: ty },
      ],
    }
  }

  return {
    d: `M ${fx} ${fy} H ${stubX} V ${ty} H ${tx}`,
    segments: [
      { kind: 'horizontal', y: fy, x1: fx, x2: stubX },
      { kind: 'vertical', x: stubX, y1: fy, y2: ty },
      { kind: 'horizontal', y: ty, x1: stubX, x2: tx },
    ],
  }
}

function buildRelationshipPath(
  fromNode: SchemaVisualizerEdgeNode,
  toNode: SchemaVisualizerEdgeNode,
  fromLayer: number,
  toLayer: number,
  fromColumn: string,
  toColumn: string,
  fromExpanded: boolean,
  toExpanded: boolean,
  laneOffset: number,
  metrics: SchemaVisualizerLayoutMetrics | undefined,
  obstacles: EdgeRect[],
  layout: SchemaVisualizerEdgeLayout,
): { d: string; segments: PathSegment[]; from: SchemaVisualizerEdgeEndpoint; to: SchemaVisualizerEdgeEndpoint } {
  const fromY = getColumnAnchorY(fromNode, fromColumn, fromExpanded, layout)
  const toY = getColumnAnchorY(toNode, toColumn, toExpanded, layout)

  const fromOnRight = fromLayer >= toLayer
  const from: SchemaVisualizerEdgeEndpoint = {
    x: fromOnRight ? fromNode.x + fromNode.width : fromNode.x,
    y: fromY,
    side: fromOnRight ? 'right' : 'left',
  }
  const to: SchemaVisualizerEdgeEndpoint = {
    x: fromOnRight ? toNode.x : toNode.x + toNode.width,
    y: toY,
    side: fromOnRight ? 'left' : 'right',
  }

  const ignoreIds = new Set([fromNode.id, toNode.id])

  if (metrics && fromLayer !== toLayer) {
    const corridorPath = buildCorridorPath(
      from,
      to,
      fromLayer,
      toLayer,
      metrics,
      laneOffset,
      obstacles,
      ignoreIds,
    )
    if (corridorPath) {
      return { ...corridorPath, from, to }
    }
  }

  if (fromLayer === toLayer) {
    return { ...buildSameLayerPath(from, to, fromNode, toNode, laneOffset, obstacles, ignoreIds), from, to }
  }

  const corridorPath = metrics
    ? buildCorridorPath(from, to, fromLayer, toLayer, metrics, laneOffset, obstacles, ignoreIds)
    : null

  if (corridorPath) {
    return { ...corridorPath, from, to }
  }

  const midX = (from.x + to.x) / 2 + laneOffset
  return {
    d: `M ${from.x} ${from.y} H ${midX} V ${to.y} H ${to.x}`,
    segments: [
      { kind: 'horizontal', y: from.y, x1: from.x, x2: midX },
      { kind: 'vertical', x: midX, y1: from.y, y2: to.y },
      { kind: 'horizontal', y: to.y, x1: midX, x2: to.x },
    ],
    from,
    to,
  }
}

function buildCorridorLaneMap(
  relationships: Array<{
    from: string
    to: string
    constraintName: string
  }>,
  nodeLayers: Map<string, number>,
): Map<string, number> {
  const groups = new Map<string, string[]>()

  for (const relationship of relationships) {
    const fromLayer = nodeLayers.get(relationship.from) ?? 0
    const toLayer = nodeLayers.get(relationship.to) ?? 0
    const minLayer = Math.min(fromLayer, toLayer)
    const maxLayer = Math.max(fromLayer, toLayer)
    const key = `${minLayer}:${maxLayer}:${relationship.from}:${relationship.to}`
    if (!groups.has(key)) {
      groups.set(key, [])
    }
    groups.get(key)!.push(relationship.constraintName)
  }

  const laneByConstraint = new Map<string, number>()
  for (const constraintNames of groups.values()) {
    const sorted = [...constraintNames].sort()
    sorted.forEach((constraintName, index) => {
      laneByConstraint.set(
        constraintName,
        (index - (sorted.length - 1) / 2) * DEFAULT_SCHEMA_VISUALIZER_EDGE_LAYOUT.pathLaneSpacing,
      )
    })
  }

  return laneByConstraint
}

export function buildSchemaVisualizerRelationshipPaths(
  nodes: SchemaVisualizerEdgeNode[],
  relationships: Array<{
    from: string
    to: string
    fromColumn: string
    toColumn: string
    constraintName: string
  }>,
  expandedNodeIds: Set<string>,
  layout: SchemaVisualizerEdgeLayout = DEFAULT_SCHEMA_VISUALIZER_EDGE_LAYOUT,
  layoutMetrics?: SchemaVisualizerLayoutMetrics,
  nodeLayers?: Map<string, number>,
): SchemaVisualizerRelationshipPath[] {
  const nodeById = new Map(nodes.map((node) => [node.id, node]))
  const layers =
    nodeLayers ??
    new Map(nodes.map((node) => [node.id, Math.round(node.x / 400)]))

  const laneByConstraint = buildCorridorLaneMap(relationships, layers)
  const sortedRelationships = [...relationships].sort(
    (a, b) =>
      (layers.get(a.from) ?? 0) - (layers.get(b.from) ?? 0) ||
      (layers.get(a.to) ?? 0) - (layers.get(b.to) ?? 0) ||
      a.from.localeCompare(b.from) ||
      a.to.localeCompare(b.to) ||
      a.fromColumn.localeCompare(b.fromColumn) ||
      a.constraintName.localeCompare(b.constraintName),
  )

  const nodeObstacles = nodes.map((node) => nodeToRect(node, 4))
  const pathObstacles: EdgeRect[] = []
  const paths: SchemaVisualizerRelationshipPath[] = []

  for (const relationship of sortedRelationships) {
    const fromNode = nodeById.get(relationship.from)
    const toNode = nodeById.get(relationship.to)
    if (!fromNode || !toNode) continue

    const fromLayer = layers.get(relationship.from) ?? 0
    const toLayer = layers.get(relationship.to) ?? 0
    const laneOffset = laneByConstraint.get(relationship.constraintName) ?? 0

    const { d, segments, from, to } = buildRelationshipPath(
      fromNode,
      toNode,
      fromLayer,
      toLayer,
      relationship.fromColumn,
      relationship.toColumn,
      expandedNodeIds.has(fromNode.id),
      expandedNodeIds.has(toNode.id),
      laneOffset,
      layoutMetrics,
      [...nodeObstacles, ...pathObstacles],
      layout,
    )

    paths.push({ d, from, to })

    for (const segment of segments) {
      pathObstacles.push(pathSegmentToRect(segment, layout.pathLaneSpacing / 2))
    }
  }

  return paths
}
