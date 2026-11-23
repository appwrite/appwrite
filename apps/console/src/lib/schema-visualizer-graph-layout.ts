export type SchemaVisualizerLayoutNode = {
  id: string
  width: number
  height: number
  label?: string
}

export type SchemaVisualizerLayoutRelationship = {
  from: string
  to: string
}

export type SchemaVisualizerLayoutOptions = {
  startX?: number
  startY?: number
  columnGap?: number
  rowGap?: number
  componentGap?: number
  /** Horizontal gap between tables placed in the same layer row. */
  innerGap?: number
  /** Max tables per row inside a layer before wrapping downward. */
  maxNodesPerRow?: number
}

export type SchemaVisualizerLayoutMetrics = {
  startX: number
  startY: number
  columnGap: number
  rowGap: number
  nodeWidth: number
  /** Average stride between layer regions (for fallbacks). */
  columnStride: number
  /** Center X of the routing corridor between layer N and layer N+1. */
  corridorXByBoundary: Map<number, number>
  /** Left X of each layer region. */
  layerStartX: Map<number, number>
}

export type SchemaVisualizerLayoutResult = {
  positions: Map<string, { x: number; y: number }>
  layers: Map<string, number>
  metrics: SchemaVisualizerLayoutMetrics
}

const DEFAULT_LAYOUT_OPTIONS: Required<SchemaVisualizerLayoutOptions> = {
  startX: 100,
  startY: 100,
  columnGap: 120,
  rowGap: 48,
  componentGap: 140,
  innerGap: 32,
  maxNodesPerRow: 2,
}

function buildConnectedComponents(
  nodeIds: string[],
  relationships: SchemaVisualizerLayoutRelationship[],
): string[][] {
  const adjacency = new Map<string, Set<string>>()
  for (const id of nodeIds) {
    adjacency.set(id, new Set())
  }

  for (const relationship of relationships) {
    adjacency.get(relationship.from)?.add(relationship.to)
    adjacency.get(relationship.to)?.add(relationship.from)
  }

  const visited = new Set<string>()
  const components: string[][] = []

  for (const id of nodeIds) {
    if (visited.has(id)) continue

    const stack = [id]
    const component: string[] = []

    while (stack.length > 0) {
      const current = stack.pop()
      if (!current || visited.has(current)) continue

      visited.add(current)
      component.push(current)

      for (const neighbor of adjacency.get(current) ?? []) {
        if (!visited.has(neighbor)) {
          stack.push(neighbor)
        }
      }
    }

    components.push(component)
  }

  return components.sort((a, b) => b.length - a.length)
}

function assignRelationshipLayers(
  componentIds: string[],
  relationships: SchemaVisualizerLayoutRelationship[],
): Map<string, number> {
  const componentSet = new Set(componentIds)
  const relevant = relationships.filter(
    (relationship) =>
      componentSet.has(relationship.from) && componentSet.has(relationship.to),
  )

  const layers = new Map<string, number>()
  for (const id of componentIds) {
    layers.set(id, 0)
  }

  let changed = true
  let iterations = 0

  while (changed && iterations < componentIds.length + 1) {
    changed = false
    iterations += 1

    for (const relationship of relevant) {
      const nextLayer = (layers.get(relationship.to) ?? 0) + 1
      const currentLayer = layers.get(relationship.from) ?? 0
      if (currentLayer < nextLayer) {
        layers.set(relationship.from, nextLayer)
        changed = true
      }
    }
  }

  return layers
}

function compressRelationshipLayers(
  componentIds: string[],
  relationships: SchemaVisualizerLayoutRelationship[],
  layers: Map<string, number>,
): Map<string, number> {
  const componentSet = new Set(componentIds)
  const relevant = relationships.filter(
    (relationship) =>
      componentSet.has(relationship.from) && componentSet.has(relationship.to),
  )

  const parentsOf = new Map<string, string[]>()
  for (const id of componentIds) {
    parentsOf.set(id, [])
  }
  for (const relationship of relevant) {
    parentsOf.get(relationship.from)?.push(relationship.to)
  }

  const nextLayers = new Map(layers)
  let changed = true

  while (changed) {
    changed = false
    for (const id of componentIds) {
      const parents = parentsOf.get(id) ?? []
      if (parents.length === 0) continue

      const minLayer = Math.max(
        ...parents.map((parentId) => (nextLayers.get(parentId) ?? 0) + 1),
      )
      const currentLayer = nextLayers.get(id) ?? 0
      if (currentLayer > minLayer) {
        nextLayers.set(id, minLayer)
        changed = true
      }
    }
  }

  return nextLayers
}

function getLayerGroups(
  componentIds: string[],
  layers: Map<string, number>,
  labels: Map<string, string>,
): Map<number, string[]> {
  const layerGroups = new Map<number, string[]>()

  for (const id of componentIds) {
    const layer = layers.get(id) ?? 0
    if (!layerGroups.has(layer)) {
      layerGroups.set(layer, [])
    }
    layerGroups.get(layer)!.push(id)
  }

  for (const [layer, ids] of layerGroups) {
    ids.sort((a, b) =>
      (labels.get(a) ?? a).localeCompare(labels.get(b) ?? b),
    )
    layerGroups.set(layer, ids)
  }

  return layerGroups
}

function indexInLayer(layerGroups: Map<number, string[]>, id: string): number {
  const layer = [...layerGroups.entries()].find(([, ids]) => ids.includes(id))?.[0]
  if (layer == null) return 0
  return layerGroups.get(layer)?.indexOf(id) ?? 0
}

function orderLayerGroups(
  componentIds: string[],
  layers: Map<string, number>,
  relationships: SchemaVisualizerLayoutRelationship[],
  labels: Map<string, string>,
): Map<number, string[]> {
  const layerGroups = getLayerGroups(componentIds, layers, labels)
  const maxLayer = Math.max(...componentIds.map((id) => layers.get(id) ?? 0))

  const barycenterForParents = (id: string) => {
    const parents = relationships
      .filter((relationship) => relationship.from === id)
      .map((relationship) => relationship.to)

    if (parents.length === 0) {
      return indexInLayer(layerGroups, id)
    }

    const parentIndices = parents.map((parentId) => indexInLayer(layerGroups, parentId))
    return parentIndices.reduce((sum, index) => sum + index, 0) / parentIndices.length
  }

  const barycenterForChildren = (id: string) => {
    const children = relationships
      .filter((relationship) => relationship.to === id)
      .map((relationship) => relationship.from)

    if (children.length === 0) {
      return indexInLayer(layerGroups, id)
    }

    const childIndices = children.map((childId) => indexInLayer(layerGroups, childId))
    return childIndices.reduce((sum, index) => sum + index, 0) / childIndices.length
  }

  for (let pass = 0; pass < 12; pass += 1) {
    for (let layer = 1; layer <= maxLayer; layer += 1) {
      const ids = layerGroups.get(layer) ?? []
      const sorted = [...ids].sort(
        (a, b) => barycenterForParents(a) - barycenterForParents(b),
      )
      layerGroups.set(layer, sorted)
    }

    for (let layer = maxLayer - 1; layer >= 0; layer -= 1) {
      const ids = layerGroups.get(layer) ?? []
      const sorted = [...ids].sort(
        (a, b) => barycenterForChildren(a) - barycenterForChildren(b),
      )
      layerGroups.set(layer, sorted)
    }
  }

  return layerGroups
}

function layoutGridComponent(
  componentIds: string[],
  nodeById: Map<string, SchemaVisualizerLayoutNode>,
  originX: number,
  originY: number,
  columnGap: number,
  rowGap: number,
): { positions: Map<string, { x: number; y: number }>; width: number; height: number } {
  const positions = new Map<string, { x: number; y: number }>()
  const cols = Math.max(
    1,
    Math.ceil(Math.sqrt(componentIds.length * 1.6)),
  )
  const maxNodeWidth = Math.max(
    ...componentIds.map((id) => nodeById.get(id)?.width ?? 0),
  )
  const columnStride = maxNodeWidth + columnGap

  const rowHeights: number[] = []
  for (let index = 0; index < componentIds.length; index += 1) {
    const row = Math.floor(index / cols)
    const node = nodeById.get(componentIds[index])
    const height = node?.height ?? 0
    rowHeights[row] = Math.max(rowHeights[row] ?? 0, height)
  }

  let y = originY
  for (let row = 0; row < rowHeights.length; row += 1) {
    let x = originX
    for (let col = 0; col < cols; col += 1) {
      const index = row * cols + col
      if (index >= componentIds.length) break

      const id = componentIds[index]
      positions.set(id, { x, y })
      x += columnStride
    }
    y += (rowHeights[row] ?? 0) + rowGap
  }

  const rows = rowHeights.length
  const width =
    Math.min(cols, componentIds.length) * maxNodeWidth +
    Math.max(0, Math.min(cols, componentIds.length) - 1) * columnGap
  const height =
    rowHeights.reduce((sum, rowHeight) => sum + rowHeight, 0) +
    Math.max(0, rows - 1) * rowGap

  return { positions, width, height }
}

function layoutLayerBlock(
  ids: string[],
  nodeById: Map<string, SchemaVisualizerLayoutNode>,
  originX: number,
  originY: number,
  innerGap: number,
  rowGap: number,
  maxNodesPerRow: number,
  positions: Map<string, { x: number; y: number }>,
): { width: number; height: number } {
  if (ids.length === 0) {
    return { width: 0, height: 0 }
  }

  const nodesPerRow = Math.min(maxNodesPerRow, ids.length)
  const rowCount = Math.ceil(ids.length / nodesPerRow)
  const rowWidths: number[] = []
  const rowHeights: number[] = []

  for (let row = 0; row < rowCount; row += 1) {
    const rowIds = ids.slice(row * nodesPerRow, (row + 1) * nodesPerRow)
    rowWidths.push(
      rowIds.reduce((sum, id) => sum + (nodeById.get(id)?.width ?? 0), 0) +
        Math.max(0, rowIds.length - 1) * innerGap,
    )
    rowHeights.push(
      Math.max(...rowIds.map((id) => nodeById.get(id)?.height ?? 0), 0),
    )
  }

  const blockWidth = Math.max(...rowWidths, 0)
  const blockHeight =
    rowHeights.reduce((sum, height) => sum + height, 0) +
    Math.max(0, rowCount - 1) * rowGap

  let y = originY
  for (let row = 0; row < rowCount; row += 1) {
    const rowIds = ids.slice(row * nodesPerRow, (row + 1) * nodesPerRow)
    let x = originX

    for (const id of rowIds) {
      const node = nodeById.get(id)
      if (!node) continue

      positions.set(id, { x, y })
      x += node.width + innerGap
    }

    y += (rowHeights[row] ?? 0) + rowGap
  }

  return { width: blockWidth, height: blockHeight }
}

function layoutComponent(
  componentIds: string[],
  nodeById: Map<string, SchemaVisualizerLayoutNode>,
  relationships: SchemaVisualizerLayoutRelationship[],
  originX: number,
  originY: number,
  columnGap: number,
  rowGap: number,
  innerGap: number,
  maxNodesPerRow: number,
  layers: Map<string, number>,
): {
  positions: Map<string, { x: number; y: number }>
  width: number
  height: number
  layerStartX: Map<number, number>
  corridorXByBoundary: Map<number, number>
} {
  const componentSet = new Set(componentIds)
  const relevant = relationships.filter(
    (relationship) =>
      componentSet.has(relationship.from) && componentSet.has(relationship.to),
  )

  if (relevant.length === 0) {
    const grid = layoutGridComponent(
      componentIds,
      nodeById,
      originX,
      originY,
      columnGap,
      rowGap,
    )
    return {
      ...grid,
      layerStartX: new Map([[0, originX]]),
      corridorXByBoundary: new Map(),
    }
  }

  const labels = new Map(
    componentIds.map((id) => [id, nodeById.get(id)?.label ?? id]),
  )
  const layerGroups = orderLayerGroups(
    componentIds,
    layers,
    relationships,
    labels,
  )

  const layerNumbers = [...layerGroups.keys()].sort((a, b) => a - b)
  const positions = new Map<string, { x: number; y: number }>()
  const layerStartX = new Map<number, number>()
  const corridorXByBoundary = new Map<number, number>()

  const layerBlocks = layerNumbers.map((layer) => {
    const ids = layerGroups.get(layer) ?? []
    const block = layoutLayerBlock(
      ids,
      nodeById,
      0,
      0,
      innerGap,
      rowGap,
      maxNodesPerRow,
      new Map(),
    )
    return { layer, ids, ...block }
  })

  const totalHeight = Math.max(...layerBlocks.map((block) => block.height), 0)

  let cursorX = originX
  for (const block of layerBlocks) {
    layerStartX.set(block.layer, cursorX)
    const blockOriginY = originY + (totalHeight - block.height) / 2

    layoutLayerBlock(
      block.ids,
      nodeById,
      cursorX,
      blockOriginY,
      innerGap,
      rowGap,
      maxNodesPerRow,
      positions,
    )

    const layerIndex = layerNumbers.indexOf(block.layer)
    const nextLayer = layerNumbers[layerIndex + 1]
    if (nextLayer != null) {
      corridorXByBoundary.set(block.layer, cursorX + block.width + columnGap / 2)
    }

    cursorX += block.width + columnGap
  }

  const width = cursorX - originX - (layerNumbers.length > 0 ? columnGap : 0)

  return {
    positions,
    width: Math.max(width, 0),
    height: totalHeight,
    layerStartX,
    corridorXByBoundary,
  }
}

/**
 * Hierarchical start-to-end layout for schema visualizer graphs.
 * Referenced tables (FK targets) sit at inline-start; dependent tables sit at inline-end.
 */
export function layoutSchemaVisualizerNodesWithMetadata(
  nodes: SchemaVisualizerLayoutNode[],
  relationships: SchemaVisualizerLayoutRelationship[],
  options: SchemaVisualizerLayoutOptions = {},
): SchemaVisualizerLayoutResult {
  const {
    startX,
    startY,
    columnGap,
    rowGap,
    componentGap,
    innerGap,
    maxNodesPerRow,
  } = { ...DEFAULT_LAYOUT_OPTIONS, ...options }

  const positions = new Map<string, { x: number; y: number }>()
  const layers = new Map<string, number>()
  const corridorXByBoundary = new Map<number, number>()
  const layerStartX = new Map<number, number>()

  if (nodes.length === 0) {
    return {
      positions,
      layers,
      metrics: {
        startX,
        startY,
        columnGap,
        rowGap,
        nodeWidth: 0,
        columnStride: 0,
        corridorXByBoundary,
        layerStartX,
      },
    }
  }

  const nodeById = new Map(nodes.map((node) => [node.id, node]))
  const nodeIds = nodes.map((node) => node.id)
  const rawComponents = buildConnectedComponents(nodeIds, relationships)

  const components: string[][] = []
  let isolatedIds: string[] = []

  for (const component of rawComponents) {
    if (component.length === 1) {
      isolatedIds.push(component[0])
      continue
    }
    components.push(component)
  }

  if (isolatedIds.length > 0) {
    isolatedIds.sort((a, b) =>
      (nodeById.get(a)?.label ?? a).localeCompare(nodeById.get(b)?.label ?? b),
    )
    components.push(isolatedIds)
  }

  const maxNodeWidth = Math.max(...nodes.map((node) => node.width))
  const columnStride = maxNodeWidth + columnGap

  let currentY = startY

  for (const componentIds of components) {
    const componentSet = new Set(componentIds)
    const relevant = relationships.filter(
      (relationship) =>
        componentSet.has(relationship.from) && componentSet.has(relationship.to),
    )

    let componentLayers = assignRelationshipLayers(componentIds, relevant)
    componentLayers = compressRelationshipLayers(
      componentIds,
      relevant,
      componentLayers,
    )

    const layout = layoutComponent(
      componentIds,
      nodeById,
      relationships,
      startX,
      currentY,
      columnGap,
      rowGap,
      innerGap,
      maxNodesPerRow,
      componentLayers,
    )

    for (const [id, position] of layout.positions) {
      positions.set(id, position)
      layers.set(id, componentLayers.get(id) ?? 0)
    }

    for (const [boundary, corridorX] of layout.corridorXByBoundary) {
      corridorXByBoundary.set(boundary, corridorX)
    }
    for (const [layer, x] of layout.layerStartX) {
      layerStartX.set(layer, x)
    }

    currentY += layout.height + componentGap
  }

  return {
    positions,
    layers,
    metrics: {
      startX,
      startY,
      columnGap,
      rowGap,
      nodeWidth: maxNodeWidth,
      columnStride,
      corridorXByBoundary,
      layerStartX,
    },
  }
}

export function layoutSchemaVisualizerNodes(
  nodes: SchemaVisualizerLayoutNode[],
  relationships: SchemaVisualizerLayoutRelationship[],
  options: SchemaVisualizerLayoutOptions = {},
): Map<string, { x: number; y: number }> {
  return layoutSchemaVisualizerNodesWithMetadata(nodes, relationships, options)
    .positions
}
