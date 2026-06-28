import { DIAGRAM_SNAP_GRID } from '@/lib/diagram-generator/constants'
import { normalizeDiagramEdge } from '@/lib/diagram-generator/edge-appearance'
import { normalizeDiagramNode } from '@/lib/diagram-generator/node-normalize'
import { getSelectedNodeIds } from '@/lib/diagram-generator/selection'
import { snapDiagramValue } from '@/lib/diagram-generator/diagram-factory'
import type {
  DiagramDocument,
  DiagramEdge,
  DiagramNode,
  DiagramSelection,
} from '@/lib/diagram-generator/types'

export const DIAGRAM_CLIPBOARD_PASTE_OFFSET = DIAGRAM_SNAP_GRID

export type DiagramClipboardPayload = {
  nodes: DiagramNode[]
  edges: DiagramEdge[]
}

function cloneDiagramNode(node: DiagramNode): DiagramNode {
  return {
    ...node,
    tableHeaders: node.tableHeaders ? [...node.tableHeaders] : undefined,
    tableRows: node.tableRows ? node.tableRows.map((row) => [...row]) : undefined,
  }
}

function cloneDiagramEdge(edge: DiagramEdge): DiagramEdge {
  return { ...edge }
}

export function getCopyableNodeIds(
  document: DiagramDocument,
  selection: DiagramSelection,
): string[] {
  const selectedNodeIds = getSelectedNodeIds(selection)
  if (selectedNodeIds.length > 0) {
    return selectedNodeIds
  }

  if (selection.type !== 'edge') return []

  const edge = document.edges.find((item) => item.id === selection.id)
  if (!edge) return []

  return [edge.fromNodeId, edge.toNodeId]
}

export function buildDiagramClipboardPayload(
  document: DiagramDocument,
  selection: DiagramSelection,
): DiagramClipboardPayload | null {
  const nodeIds = getCopyableNodeIds(document, selection)
  if (nodeIds.length === 0) return null

  const nodeIdSet = new Set(nodeIds)
  const nodes = document.nodes
    .filter((node) => nodeIdSet.has(node.id))
    .map(cloneDiagramNode)
  if (nodes.length === 0) return null

  const edges = document.edges
    .filter(
      (edge) => nodeIdSet.has(edge.fromNodeId) && nodeIdSet.has(edge.toNodeId),
    )
    .map(cloneDiagramEdge)

  return { nodes, edges }
}

export function pasteDiagramClipboardPayload(
  payload: DiagramClipboardPayload,
  pasteGeneration: number,
): {
  nodes: DiagramNode[]
  edges: DiagramEdge[]
  selectedNodeIds: string[]
} {
  const offset = DIAGRAM_CLIPBOARD_PASTE_OFFSET * Math.max(pasteGeneration, 1)
  const idMap = new Map<string, string>()

  const nodes = payload.nodes.map((node) => {
    const nextId = crypto.randomUUID()
    idMap.set(node.id, nextId)

    return normalizeDiagramNode({
      ...node,
      id: nextId,
      x: snapDiagramValue(node.x + offset),
      y: snapDiagramValue(node.y + offset),
    })
  })

  const edges = payload.edges.flatMap((edge) => {
    const fromNodeId = idMap.get(edge.fromNodeId)
    const toNodeId = idMap.get(edge.toNodeId)
    if (!fromNodeId || !toNodeId) return []

    return [
      normalizeDiagramEdge({
        ...edge,
        id: crypto.randomUUID(),
        fromNodeId,
        toNodeId,
      }),
    ]
  })

  return {
    nodes,
    edges,
    selectedNodeIds: nodes.map((node) => node.id),
  }
}
