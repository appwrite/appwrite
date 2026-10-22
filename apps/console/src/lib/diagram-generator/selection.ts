import type { DiagramNode, DiagramSelection, DiagramDocument } from '@/lib/diagram-generator/types'

export function getSelectedNodeIds(selection: DiagramSelection): string[] {
  if (selection.type === 'node') return [selection.id]
  if (selection.type === 'nodes') return selection.ids
  return []
}

export function isNodeSelected(selection: DiagramSelection, nodeId: string): boolean {
  return getSelectedNodeIds(selection).includes(nodeId)
}

export function selectDiagramNodes(ids: string[]): DiagramSelection {
  const unique = [...new Set(ids)]
  if (unique.length === 0) return { type: 'none' }
  if (unique.length === 1) return { type: 'node', id: unique[0] }
  return { type: 'nodes', ids: unique }
}

export function selectDiagramNode(
  selection: DiagramSelection,
  nodeId: string,
  options?: { additive?: boolean; toggle?: boolean },
): DiagramSelection {
  const currentIds = getSelectedNodeIds(selection)

  if (options?.toggle) {
    if (currentIds.includes(nodeId)) {
      return selectDiagramNodes(currentIds.filter((id) => id !== nodeId))
    }
    return selectDiagramNodes([...currentIds, nodeId])
  }

  if (options?.additive) {
    if (currentIds.includes(nodeId)) return selection
    return selectDiagramNodes([...currentIds, nodeId])
  }

  return { type: 'node', id: nodeId }
}

export function resolveNodePointerSelection(
  selection: DiagramSelection,
  nodeId: string,
  modifiers: { shiftKey: boolean; metaKey: boolean; ctrlKey: boolean },
): { selection: DiagramSelection; dragNodeIds: string[] } {
  let nextSelection = selection

  if (modifiers.metaKey || modifiers.ctrlKey) {
    nextSelection = selectDiagramNode(selection, nodeId, { toggle: true })
  } else if (modifiers.shiftKey) {
    nextSelection = selectDiagramNode(selection, nodeId, { additive: true })
  } else if (!isNodeSelected(selection, nodeId)) {
    nextSelection = { type: 'node', id: nodeId }
  }

  const selectedIds = getSelectedNodeIds(nextSelection)
  const dragNodeIds = selectedIds.includes(nodeId) ? selectedIds : [nodeId]

  return { selection: nextSelection, dragNodeIds }
}

export function getDiagramNodesInRect(
  nodes: DiagramNode[],
  rect: { x: number; y: number; width: number; height: number },
): string[] {
  const left = Math.min(rect.x, rect.x + rect.width)
  const right = Math.max(rect.x, rect.x + rect.width)
  const top = Math.min(rect.y, rect.y + rect.height)
  const bottom = Math.max(rect.y, rect.y + rect.height)

  return nodes
    .filter((node) => {
      const nodeRight = node.x + node.width
      const nodeBottom = node.y + node.height
      return node.x < right && nodeRight > left && node.y < bottom && nodeBottom > top
    })
    .map((node) => node.id)
}

export function sanitizeDiagramSelection(
  selection: DiagramSelection,
  document: DiagramDocument,
): DiagramSelection {
  if (selection.type === 'node') {
    return document.nodes.some((node) => node.id === selection.id)
      ? selection
      : { type: 'none' }
  }

  if (selection.type === 'nodes') {
    const ids = selection.ids.filter((id) =>
      document.nodes.some((node) => node.id === id),
    )
    if (ids.length === 0) return { type: 'none' }
    if (ids.length === 1) return { type: 'node', id: ids[0] }
    return { type: 'nodes', ids }
  }

  if (selection.type === 'edge') {
    return document.edges.some((edge) => edge.id === selection.id)
      ? selection
      : { type: 'none' }
  }

  return selection
}
