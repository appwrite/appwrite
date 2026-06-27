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

function getSegmentAngle(from: DiagramPoint, to: DiagramPoint): number {
  return Math.atan2(to.y - from.y, to.x - from.x)
}

/** Filled triangle path for an arrowhead tip pointing along `angle`. */
export function buildDiagramEdgeArrowheadPath(
  arrow: DiagramEdgeArrowhead,
  size = 8,
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
      return { x: node.x + node.width / 2, y: node.y }
    case 'bottom':
      return { x: node.x + node.width / 2, y: node.y + node.height }
    case 'left':
      return { x: node.x, y: node.y + node.height / 2 }
    case 'right':
      return { x: node.x + node.width, y: node.y + node.height / 2 }
  }
}

export function extendDiagramAnchor(
  point: DiagramPoint,
  side: DiagramAnchorSide,
  distance: number,
): DiagramPoint {
  switch (side) {
    case 'top':
      return { x: point.x, y: point.y - distance }
    case 'bottom':
      return { x: point.x, y: point.y + distance }
    case 'left':
      return { x: point.x - distance, y: point.y }
    case 'right':
      return { x: point.x + distance, y: point.y }
  }
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

export function buildDiagramEdgePath(
  edge: DiagramEdge,
  fromNode: DiagramNode,
  toNode: DiagramNode,
): DiagramEdgePath {
  const start = getDiagramNodeAnchor(fromNode, edge.fromSide)
  const end = getDiagramNodeAnchor(toNode, edge.toSide)
  const startStub = extendDiagramAnchor(start, edge.fromSide, DIAGRAM_EDGE_STUB)
  const endStub = extendDiagramAnchor(end, edge.toSide, DIAGRAM_EDGE_STUB)

  const horizontalFirst =
    edge.fromSide === 'left' ||
    edge.fromSide === 'right' ||
    edge.toSide === 'left' ||
    edge.toSide === 'right'

  let d: string
  if (horizontalFirst) {
    const midX = (startStub.x + endStub.x) / 2
    d = [
      `M ${start.x} ${start.y}`,
      `L ${startStub.x} ${startStub.y}`,
      `L ${midX} ${startStub.y}`,
      `L ${midX} ${endStub.y}`,
      `L ${endStub.x} ${endStub.y}`,
      `L ${end.x} ${end.y}`,
    ].join(' ')
  } else {
    const midY = (startStub.y + endStub.y) / 2
    d = [
      `M ${start.x} ${start.y}`,
      `L ${startStub.x} ${startStub.y}`,
      `L ${startStub.x} ${midY}`,
      `L ${endStub.x} ${midY}`,
      `L ${endStub.x} ${endStub.y}`,
      `L ${end.x} ${end.y}`,
    ].join(' ')
  }

  const labelX = horizontalFirst
    ? (startStub.x + endStub.x) / 2
    : startStub.x
  const labelY = horizontalFirst
    ? (startStub.y + endStub.y) / 2
    : (startStub.y + endStub.y) / 2

  return {
    id: edge.id,
    d,
    lineStyle: edge.lineStyle,
    arrow: edge.arrow,
    strokeTone: edge.strokeTone,
    label: edge.label,
    labelX,
    labelY,
    forwardArrow: edgeUsesForwardArrow(edge.arrow)
      ? {
          tipX: end.x,
          tipY: end.y,
          angle: getSegmentAngle(endStub, end),
        }
      : undefined,
    backwardArrow: edgeUsesBackwardArrow(edge.arrow)
      ? {
          tipX: start.x,
          tipY: start.y,
          angle: getSegmentAngle(start, startStub),
        }
      : undefined,
  }
}

export function buildDiagramEdgePaths(
  nodes: DiagramNode[],
  edges: DiagramEdge[],
): DiagramEdgePath[] {
  const nodeById = new Map(nodes.map((node) => [node.id, node]))

  return edges.flatMap((edge) => {
    const fromNode = nodeById.get(edge.fromNodeId)
    const toNode = nodeById.get(edge.toNodeId)
    if (!fromNode || !toNode) return []
    return [buildDiagramEdgePath(edge, fromNode, toNode)]
  })
}
