import { snapDiagramValue } from '@/lib/diagram-generator/templates'
import type { DiagramNode } from '@/lib/diagram-generator/types'

/** Max distance (document px) to snap edges/centers together while dragging. */
export const DIAGRAM_ALIGN_SNAP_THRESHOLD = 8

export type DiagramAlignGuide =
  | {
      orientation: 'vertical'
      position: number
      start: number
      end: number
    }
  | {
      orientation: 'horizontal'
      position: number
      start: number
      end: number
    }

type DiagramNodeBounds = {
  left: number
  right: number
  top: number
  bottom: number
  centerX: number
  centerY: number
}

type AxisSnapResult = {
  offset: number
  line: number
  partnerBounds: DiagramNodeBounds
}

export function getDiagramNodeBounds(node: Pick<DiagramNode, 'x' | 'y' | 'width' | 'height'>): DiagramNodeBounds {
  return {
    left: node.x,
    right: node.x + node.width,
    top: node.y,
    bottom: node.y + node.height,
    centerX: node.x + node.width / 2,
    centerY: node.y + node.height / 2,
  }
}

function getBoundsAtPosition(
  node: Pick<DiagramNode, 'width' | 'height'>,
  x: number,
  y: number,
): DiagramNodeBounds {
  return getDiagramNodeBounds({ ...node, x, y })
}

function getAxisAnchors(bounds: DiagramNodeBounds): [number, number, number] {
  return [bounds.left, bounds.centerX, bounds.right]
}

function getVerticalAnchors(bounds: DiagramNodeBounds): [number, number, number] {
  return [bounds.top, bounds.centerY, bounds.bottom]
}

function snapToAxisTargets(
  anchors: [number, number, number],
  targets: Array<{ value: number; bounds: DiagramNodeBounds }>,
  threshold: number,
): AxisSnapResult | null {
  let best: (AxisSnapResult & { distance: number }) | null = null

  for (const anchor of anchors) {
    for (const target of targets) {
      const offset = target.value - anchor
      const distance = Math.abs(offset)
      if (distance > threshold) continue
      if (!best || distance < best.distance) {
        best = {
          offset,
          line: target.value,
          partnerBounds: target.bounds,
          distance,
        }
      }
    }
  }

  if (!best) return null
  return {
    offset: best.offset,
    line: best.line,
    partnerBounds: best.partnerBounds,
  }
}

export function applyDiagramShiftAxisConstraint(
  deltaX: number,
  deltaY: number,
  shiftKey: boolean,
): { deltaX: number; deltaY: number } {
  if (!shiftKey) return { deltaX, deltaY }
  if (Math.abs(deltaX) >= Math.abs(deltaY)) {
    return { deltaX, deltaY: 0 }
  }
  return { deltaX: 0, deltaY }
}

function buildGuideSpan(
  a: DiagramNodeBounds,
  b: DiagramNodeBounds,
): { start: number; end: number } {
  return {
    start: Math.min(a.top, b.top),
    end: Math.max(a.bottom, b.bottom),
  }
}

function buildHorizontalGuideSpan(
  a: DiagramNodeBounds,
  b: DiagramNodeBounds,
): { start: number; end: number } {
  return {
    start: Math.min(a.left, b.left),
    end: Math.max(a.right, b.right),
  }
}

export function resolveDiagramNodeDragPosition(options: {
  node: DiagramNode
  originX: number
  originY: number
  deltaX: number
  deltaY: number
  shiftKey: boolean
  otherNodes: DiagramNode[]
  snapToGrid?: boolean
}): {
  x: number
  y: number
  guides: DiagramAlignGuide[]
} {
  const {
    node,
    originX,
    originY,
    deltaX,
    deltaY,
    shiftKey,
    otherNodes,
    snapToGrid = true,
  } = options

  const constrained = applyDiagramShiftAxisConstraint(deltaX, deltaY, shiftKey)
  let x = originX + constrained.deltaX
  let y = originY + constrained.deltaY

  const draggedBounds = getBoundsAtPosition(node, x, y)
  const guides: DiagramAlignGuide[] = []

  const xTargets: Array<{ value: number; bounds: DiagramNodeBounds }> = []
  const yTargets: Array<{ value: number; bounds: DiagramNodeBounds }> = []

  for (const other of otherNodes) {
    if (other.id === node.id) continue
    const bounds = getDiagramNodeBounds(other)
    for (const value of getAxisAnchors(bounds)) {
      xTargets.push({ value, bounds })
    }
    for (const value of getVerticalAnchors(bounds)) {
      yTargets.push({ value, bounds })
    }
  }

  const xSnap = snapToAxisTargets(getAxisAnchors(draggedBounds), xTargets, DIAGRAM_ALIGN_SNAP_THRESHOLD)
  const ySnap = snapToAxisTargets(getVerticalAnchors(draggedBounds), yTargets, DIAGRAM_ALIGN_SNAP_THRESHOLD)

  let xSnapped = false
  let ySnapped = false

  if (xSnap) {
    x += xSnap.offset
    xSnapped = true
    const nextBounds = getBoundsAtPosition(node, x, y)
    const span = buildHorizontalGuideSpan(nextBounds, xSnap.partnerBounds)
    guides.push({
      orientation: 'vertical',
      position: xSnap.line,
      start: span.start,
      end: span.end,
    })
  }

  if (ySnap) {
    y += ySnap.offset
    ySnapped = true
    const nextBounds = getBoundsAtPosition(node, x, y)
    const span = buildGuideSpan(nextBounds, ySnap.partnerBounds)
    guides.push({
      orientation: 'horizontal',
      position: ySnap.line,
      start: span.start,
      end: span.end,
    })
  }

  if (snapToGrid) {
    if (!xSnapped) x = snapDiagramValue(x)
    if (!ySnapped) y = snapDiagramValue(y)
  }

  return { x, y, guides }
}
