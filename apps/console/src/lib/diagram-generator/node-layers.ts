import { arrayMove } from '@dnd-kit/sortable'
import type { DiagramNode } from '@/lib/diagram-generator/types'

export type DiagramLayerAction = 'forward' | 'backward' | 'front' | 'back'

export function getDiagramNodeLayerIndex(
  nodes: DiagramNode[],
  nodeId: string,
): number {
  return nodes.findIndex((node) => node.id === nodeId)
}

export function canMoveDiagramNodeLayer(
  nodes: DiagramNode[],
  nodeId: string,
  action: DiagramLayerAction,
): boolean {
  const index = getDiagramNodeLayerIndex(nodes, nodeId)
  if (index === -1) return false

  switch (action) {
    case 'forward':
      return index < nodes.length - 1
    case 'backward':
      return index > 0
    case 'front':
      return index < nodes.length - 1
    case 'back':
      return index > 0
  }
}

export function moveDiagramNodeLayer(
  nodes: DiagramNode[],
  nodeId: string,
  action: DiagramLayerAction,
): DiagramNode[] {
  const index = getDiagramNodeLayerIndex(nodes, nodeId)
  if (index === -1 || !canMoveDiagramNodeLayer(nodes, nodeId, action)) {
    return nodes
  }

  const next = [...nodes]
  const [node] = next.splice(index, 1)

  switch (action) {
    case 'forward':
      next.splice(index + 1, 0, node)
      break
    case 'backward':
      next.splice(index - 1, 0, node)
      break
    case 'front':
      next.push(node)
      break
    case 'back':
      next.unshift(node)
      break
  }

  return next
}

export function formatDiagramLayerLabel(index: number, total: number): string {
  if (index === -1 || total === 0) return 'Unknown layer'
  return `Layer ${index + 1} of ${total}`
}

export type DiagramLayerListItem = {
  node: DiagramNode
  /** Stack index where 0 is back and length - 1 is front. */
  index: number
}

/** Front-most layer first, matching common design-tool layer panels. */
export function getDiagramLayerListItems(
  nodes: DiagramNode[],
): DiagramLayerListItem[] {
  return nodes
    .map((node, index) => ({ node, index }))
    .reverse()
}

/** Reorder stack after dragging in the front-first layer list. */
export function reorderDiagramNodesInDisplayOrder(
  nodes: DiagramNode[],
  activeId: string,
  overId: string,
): DiagramNode[] {
  if (activeId === overId) return nodes

  const displayIds = nodes.map((node) => node.id).reverse()
  const oldIndex = displayIds.indexOf(activeId)
  const newIndex = displayIds.indexOf(overId)
  if (oldIndex === -1 || newIndex === -1) return nodes

  const nextDisplayIds = arrayMove(displayIds, oldIndex, newIndex)
  const nodeById = new Map(nodes.map((node) => [node.id, node]))
  return nextDisplayIds.reverse().map((id) => nodeById.get(id)!)
}
