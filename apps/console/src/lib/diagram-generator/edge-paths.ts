import type { DiagramAnchorSide, DiagramEdge, DiagramNode } from '@/lib/diagram-generator/types'
import { DIAGRAM_EDGE_STUB } from '@/lib/diagram-generator/constants'
import {
  edgeUsesBackwardArrow,
  edgeUsesForwardArrow,
} from '@/lib/diagram-generator/edge-appearance'

export type DiagramPoint = { x: number; y: number }

export type DiagramEdgeArrowhead = {
  tipX: number
  tipY: number
  angle: number
}

export type DiagramEdgePath = {
  id: string
  d: string
  lineStyle: DiagramEdge['lineStyle']
  arrow: DiagramEdge['arrow']
  strokeTone: DiagramEdge['strokeTone']
  label?: string
  labelX: number
  labelY: number
  forwardArrow?: DiagramEdgeArrowhead
  backwardArrow?: DiagramEdgeArrowhead
}

const MIN_EDGE_STUB = 8
const MIN_EDGE_MIDDLE = 4
export const DIAGRAM_EDGE_ARROWHEAD_SIZE = 8

function snapDiagramCoord(value: number): number {
  return Math.round(value)
}

function snapPoint(point: DiagramPoint): DiagramPoint {
  return {
    x: snapDiagramCoord(point.x),
    y: snapDiagramCoord(point.y),
  }
}

function isVerticalAnchorSide(side: DiagramAnchorSide): boolean {
  return side === 'top' || side === 'bottom'
}

function getSegmentAngle(from: DiagramPoint, to: DiagramPoint): number {
  return Math.atan2(to.y - from.y, to.x - from.x)
}

/** Point where the stroke should end so it meets the filled arrowhead base. */
function getDiagramEdgeArrowBase(
  tip: DiagramPoint,
  approach: DiagramPoint,
  size = DIAGRAM_EDGE_ARROWHEAD_SIZE,
): DiagramPoint {
  const angle = getSegmentAngle(approach, tip)
  return snapPoint({
    x: tip.x - Math.cos(angle) * size,
    y: tip.y - Math.sin(angle) * size,
  })
}

/** Filled triangle path for an arrowhead tip pointing along `angle`. */
export function buildDiagramEdgeArrowheadPath(
  arrow: DiagramEdgeArrowhead,
  size = DIAGRAM_EDGE_ARROWHEAD_SIZE,
): string {
  const halfWidth = size / 2
  const baseX = arrow.tipX - Math.cos(arrow.angle) * size
  const baseY = arrow.tipY - Math.sin(arrow.angle) * size
  const offsetX = Math.sin(arrow.angle) * halfWidth
  const offsetY = -Math.cos(arrow.angle) * halfWidth

  const leftX = baseX + offsetX
  const leftY = baseY + offsetY
  const rightX = baseX - offsetX
  const rightY = baseY - offsetY

  return `M ${arrow.tipX} ${arrow.tipY} L ${leftX} ${leftY} L ${rightX} ${rightY} Z`
}

export function getDiagramNodeAnchor(
  node: Pick<DiagramNode, 'x' | 'y' | 'width' | 'height'>,
  side: DiagramAnchorSide,
): DiagramPoint {
  switch (side) {
    case 'top':
      return snapPoint({ x: node.x + node.width / 2, y: node.y })
    case 'bottom':
      return snapPoint({ x: node.x + node.width / 2, y: node.y + node.height })
    case 'left':
      return snapPoint({ x: node.x, y: node.y + node.height / 2 })
    case 'right':
      return snapPoint({ x: node.x + node.width, y: node.y + node.height / 2 })
  }
}

export function extendDiagramAnchor(
  point: DiagramPoint,
  side: DiagramAnchorSide,
  distance: number,
): DiagramPoint {
  switch (side) {
    case 'top':
      return snapPoint({ x: point.x, y: point.y - distance })
    case 'bottom':
      return snapPoint({ x: point.x, y: point.y + distance })
    case 'left':
      return snapPoint({ x: point.x - distance, y: point.y })
    case 'right':
      return snapPoint({ x: point.x + distance, y: point.y })
  }
}

function getAnchorSpan(
  start: DiagramPoint,
  end: DiagramPoint,
  fromSide: DiagramAnchorSide,
  toSide: DiagramAnchorSide,
): number {
  if (fromSide === 'bottom' && toSide === 'top') {
    return Math.max(0, end.y - start.y)
  }
  if (fromSide === 'top' && toSide === 'bottom') {
    return Math.max(0, start.y - end.y)
  }
  if (fromSide === 'right' && toSide === 'left') {
    return Math.max(0, end.x - start.x)
  }
  if (fromSide === 'left' && toSide === 'right') {
    return Math.max(0, start.x - end.x)
  }

  if (isVerticalAnchorSide(fromSide) && isVerticalAnchorSide(toSide)) {
    return Math.abs(end.y - start.y)
  }

  if (!isVerticalAnchorSide(fromSide) && !isVerticalAnchorSide(toSide)) {
    return Math.abs(end.x - start.x)
  }

  return Math.max(Math.abs(end.x - start.x), Math.abs(end.y - start.y))
}

/** Shrink stub length when anchor gap is tight so paths do not backtrack. */
export function resolveDiagramEdgeStub(
  start: DiagramPoint,
  end: DiagramPoint,
  fromSide: DiagramAnchorSide,
  toSide: DiagramAnchorSide,
): number {
  const span = getAnchorSpan(start, end, fromSide, toSide)
  if (span <= 0) return MIN_EDGE_STUB

  const maxStub = Math.floor(span / 2) - MIN_EDGE_MIDDLE
  if (maxStub < MIN_EDGE_STUB) {
    return Math.max(4, Math.floor(span / 3))
  }

  return Math.min(DIAGRAM_EDGE_STUB, maxStub)
}

export function pickDiagramAnchorSides(
  from: Pick<DiagramNode, 'x' | 'y' | 'width' | 'height'>,
  to: Pick<DiagramNode, 'x' | 'y' | 'width' | 'height'>,
): { fromSide: DiagramAnchorSide; toSide: DiagramAnchorSide } {
  const fromCenter = {
    x: from.x + from.width / 2,
    y: from.y + from.height / 2,
  }
  const toCenter = {
    x: to.x + to.width / 2,
    y: to.y + to.height / 2,
  }
  const dx = toCenter.x - fromCenter.x
  const dy = toCenter.y - fromCenter.y

  if (Math.abs(dx) > Math.abs(dy)) {
    return dx > 0
      ? { fromSide: 'right', toSide: 'left' }
      : { fromSide: 'left', toSide: 'right' }
  }

  return dy > 0
    ? { fromSide: 'bottom', toSide: 'top' }
    : { fromSide: 'top', toSide: 'bottom' }
}

type EdgeLayout = {
  edge: DiagramEdge
  start: DiagramPoint
  end: DiagramPoint
  startStub: DiagramPoint
  endStub: DiagramPoint
  verticalFirst: boolean
}

function pointsToPath(points: DiagramPoint[]): string {
  return points
    .map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`)
    .join(' ')
}

function buildOrthogonalPath(
  start: DiagramPoint,
  end: DiagramPoint,
  startStub: DiagramPoint,
  endStub: DiagramPoint,
  verticalFirst: boolean,
  options?: {
    busX?: number
    busY?: number
  },
): string {
  const midY =
    options?.busY ?? snapDiagramCoord((startStub.y + endStub.y) / 2)
  const midX =
    options?.busX ?? snapDiagramCoord((startStub.x + endStub.x) / 2)

  const points: DiagramPoint[] = [start, startStub]

  if (verticalFirst) {
    points.push({ x: startStub.x, y: midY })
    points.push({ x: endStub.x, y: midY })
  } else {
    points.push({ x: midX, y: startStub.y })
    points.push({ x: midX, y: endStub.y })
  }

  points.push(endStub, end)

  return pointsToPath(points)
}

function computeEdgeLayout(
  edge: DiagramEdge,
  fromNode: DiagramNode,
  toNode: DiagramNode,
): EdgeLayout {
  const start = getDiagramNodeAnchor(fromNode, edge.fromSide)
  const end = getDiagramNodeAnchor(toNode, edge.toSide)
  const stub = resolveDiagramEdgeStub(start, end, edge.fromSide, edge.toSide)
  const startStub = extendDiagramAnchor(start, edge.fromSide, stub)
  const endStub = extendDiagramAnchor(end, edge.toSide, stub)
  const verticalFirst = isVerticalAnchorSide(edge.fromSide)

  return {
    edge,
    start,
    end,
    startStub,
    endStub,
    verticalFirst,
  }
}

function computeFanOutBusY(layouts: EdgeLayout[]): number {
  const startStubY = layouts[0]?.startStub.y ?? 0
  const endStubYs = layouts.map((layout) => layout.endStub.y)
  const minEndStubY = Math.min(...endStubYs)
  const maxEndStubY = Math.max(...endStubYs)

  if (startStubY <= minEndStubY) {
    return snapDiagramCoord((startStubY + minEndStubY) / 2)
  }

  return snapDiagramCoord((startStubY + maxEndStubY) / 2)
}

function computeConvergeBusY(layouts: EdgeLayout[]): number {
  const endStubY = layouts[0]?.endStub.y ?? 0
  const startStubYs = layouts.map((layout) => layout.startStub.y)
  const minStartStubY = Math.min(...startStubYs)
  const maxStartStubY = Math.max(...startStubYs)

  if (minStartStubY <= endStubY) {
    return snapDiagramCoord((maxStartStubY + endStubY) / 2)
  }

  return snapDiagramCoord((minStartStubY + endStubY) / 2)
}

function computeFanOutBusX(layouts: EdgeLayout[]): number {
  const startStubX = layouts[0]?.startStub.x ?? 0
  const endStubXs = layouts.map((layout) => layout.endStub.x)
  const minEndStubX = Math.min(...endStubXs)
  const maxEndStubX = Math.max(...endStubXs)

  if (startStubX <= minEndStubX) {
    return snapDiagramCoord((startStubX + minEndStubX) / 2)
  }

  return snapDiagramCoord((startStubX + maxEndStubX) / 2)
}

function computeConvergeBusX(layouts: EdgeLayout[]): number {
  const endStubX = layouts[0]?.endStub.x ?? 0
  const startStubXs = layouts.map((layout) => layout.startStub.x)
  const minStartStubX = Math.min(...startStubXs)
  const maxStartStubX = Math.max(...startStubXs)

  if (minStartStubX <= endStubX) {
    return snapDiagramCoord((maxStartStubX + endStubX) / 2)
  }

  return snapDiagramCoord((minStartStubX + endStubX) / 2)
}

function buildDiagramEdgePathFromLayout(
  layout: EdgeLayout,
  options?: { busX?: number; busY?: number },
): DiagramEdgePath {
  const { edge, start, end, startStub, endStub, verticalFirst } = layout
  const hasForwardArrow = edgeUsesForwardArrow(edge.arrow)
  const hasBackwardArrow = edgeUsesBackwardArrow(edge.arrow)

  const pathStart = hasBackwardArrow
    ? getDiagramEdgeArrowBase(start, startStub)
    : start
  const pathEnd = hasForwardArrow
    ? getDiagramEdgeArrowBase(end, endStub)
    : end

  const d = buildOrthogonalPath(
    pathStart,
    pathEnd,
    startStub,
    endStub,
    verticalFirst,
    {
      busX: options?.busX,
      busY: options?.busY,
    },
  )

  const labelX = snapDiagramCoord(
    options?.busX ?? (startStub.x + endStub.x) / 2,
  )
  const labelY = snapDiagramCoord(
    options?.busY ?? (startStub.y + endStub.y) / 2,
  )

  return {
    id: edge.id,
    d,
    lineStyle: edge.lineStyle,
    arrow: edge.arrow,
    strokeTone: edge.strokeTone,
    label: edge.label,
    labelX,
    labelY,
    forwardArrow: hasForwardArrow
      ? {
          tipX: end.x,
          tipY: end.y,
          angle: getSegmentAngle(endStub, end),
        }
      : undefined,
    backwardArrow: hasBackwardArrow
      ? {
          tipX: start.x,
          tipY: start.y,
          angle: getSegmentAngle(startStub, start),
        }
      : undefined,
  }
}

export function buildDiagramEdgePath(
  edge: DiagramEdge,
  fromNode: DiagramNode,
  toNode: DiagramNode,
): DiagramEdgePath {
  return buildDiagramEdgePathFromLayout(computeEdgeLayout(edge, fromNode, toNode))
}

export function buildDiagramEdgePaths(
  nodes: DiagramNode[] | undefined,
  edges: DiagramEdge[] | undefined,
): DiagramEdgePath[] {
  const safeNodes = Array.isArray(nodes) ? nodes : []
  const safeEdges = Array.isArray(edges) ? edges : []
  const nodeById = new Map(safeNodes.map((node) => [node.id, node]))

  const layouts = safeEdges.flatMap((edge) => {
    const fromNode = nodeById.get(edge.fromNodeId)
    const toNode = nodeById.get(edge.toNodeId)
    if (!fromNode || !toNode) return []
    return [computeEdgeLayout(edge, fromNode, toNode)]
  })

  const fanOutBusY = new Map<string, number>()
  const fanOutGroups = new Map<string, EdgeLayout[]>()
  for (const layout of layouts) {
    if (!layout.verticalFirst) continue
    const key = `from:${layout.edge.fromNodeId}:${layout.edge.fromSide}`
    const group = fanOutGroups.get(key) ?? []
    group.push(layout)
    fanOutGroups.set(key, group)
  }
  for (const [key, group] of fanOutGroups) {
    if (group.length < 2) continue
    fanOutBusY.set(key, computeFanOutBusY(group))
  }

  const convergeBusY = new Map<string, number>()
  const convergeGroups = new Map<string, EdgeLayout[]>()
  for (const layout of layouts) {
    if (!layout.verticalFirst) continue
    const key = `to:${layout.edge.toNodeId}:${layout.edge.toSide}`
    const group = convergeGroups.get(key) ?? []
    group.push(layout)
    convergeGroups.set(key, group)
  }
  for (const [key, group] of convergeGroups) {
    if (group.length < 2) continue
    convergeBusY.set(key, computeConvergeBusY(group))
  }

  const fanOutBusX = new Map<string, number>()
  const fanOutHorizontalGroups = new Map<string, EdgeLayout[]>()
  for (const layout of layouts) {
    if (layout.verticalFirst) continue
    const key = `from:${layout.edge.fromNodeId}:${layout.edge.fromSide}`
    const group = fanOutHorizontalGroups.get(key) ?? []
    group.push(layout)
    fanOutHorizontalGroups.set(key, group)
  }
  for (const [key, group] of fanOutHorizontalGroups) {
    if (group.length < 2) continue
    fanOutBusX.set(key, computeFanOutBusX(group))
  }

  const convergeBusX = new Map<string, number>()
  const convergeHorizontalGroups = new Map<string, EdgeLayout[]>()
  for (const layout of layouts) {
    if (layout.verticalFirst) continue
    const key = `to:${layout.edge.toNodeId}:${layout.edge.toSide}`
    const group = convergeHorizontalGroups.get(key) ?? []
    group.push(layout)
    convergeHorizontalGroups.set(key, group)
  }
  for (const [key, group] of convergeHorizontalGroups) {
    if (group.length < 2) continue
    convergeBusX.set(key, computeConvergeBusX(group))
  }

  return layouts.map((layout) => {
    const fromKey = `from:${layout.edge.fromNodeId}:${layout.edge.fromSide}`
    const toKey = `to:${layout.edge.toNodeId}:${layout.edge.toSide}`

    return buildDiagramEdgePathFromLayout(layout, {
      busY: fanOutBusY.get(fromKey) ?? convergeBusY.get(toKey),
      busX: fanOutBusX.get(fromKey) ?? convergeBusX.get(toKey),
    })
  })
}
