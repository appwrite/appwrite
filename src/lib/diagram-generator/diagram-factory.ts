import {
  DIAGRAM_DEFAULT_HEIGHT,
  DIAGRAM_DEFAULT_WIDTH,
  DIAGRAM_NODE_DEFAULTS,
  DIAGRAM_NODE_KIND_LABELS,
  DIAGRAM_SNAP_GRID,
} from '@/lib/diagram-generator/constants'
import { pickDiagramAnchorSides } from '@/lib/diagram-generator/edge-paths'
import { getDefaultDiagramEdgeAppearance } from '@/lib/diagram-generator/edge-appearance'
import { getDiagramNodeCreateDefaults } from '@/lib/diagram-generator/node-normalize'
import type {
  DiagramDocument,
  DiagramEdge,
  DiagramNode,
  DiagramNodeKind,
} from '@/lib/diagram-generator/types'
import { DEFAULT_COVER_THEME_ID } from '@/lib/cover-generator/themes'

function createId(): string {
  return crypto.randomUUID()
}

export function snapDiagramValue(value: number): number {
  return Math.round(value / DIAGRAM_SNAP_GRID) * DIAGRAM_SNAP_GRID
}

export function createDefaultDiagramDocument(): DiagramDocument {
  return {
    title: 'Untitled diagram',
    theme: DEFAULT_COVER_THEME_ID,
    width: DIAGRAM_DEFAULT_WIDTH,
    height: DIAGRAM_DEFAULT_HEIGHT,
    format: 'png',
    nodes: [],
    edges: [],
  }
}

export function createDiagramNode(
  kind: DiagramNodeKind,
  position: { x: number; y: number },
  overrides?: Partial<
    Pick<
      DiagramNode,
      | 'label'
      | 'subtitle'
      | 'width'
      | 'height'
      | 'iconSrc'
      | 'imageSrc'
      | 'focusX'
      | 'focusY'
      | 'tableHeaders'
      | 'tableRows'
    >
  >,
): DiagramNode {
  const defaults = DIAGRAM_NODE_DEFAULTS[kind]
  const kindDefaults = getDiagramNodeCreateDefaults(kind)

  return {
    id: createId(),
    kind,
    x: snapDiagramValue(position.x),
    y: snapDiagramValue(position.y),
    width: overrides?.width ?? defaults.width,
    height: overrides?.height ?? defaults.height,
    label: overrides?.label ?? kindDefaults.label ?? DIAGRAM_NODE_KIND_LABELS[kind],
    subtitle: overrides?.subtitle,
    iconSrc: overrides?.iconSrc ?? kindDefaults.iconSrc,
    imageSrc: overrides?.imageSrc ?? kindDefaults.imageSrc,
    focusX: overrides?.focusX ?? kindDefaults.focusX,
    focusY: overrides?.focusY ?? kindDefaults.focusY,
    tableHeaders: overrides?.tableHeaders ?? kindDefaults.tableHeaders,
    tableRows: overrides?.tableRows ?? kindDefaults.tableRows,
  }
}

export function createDiagramEdge(
  fromNode: DiagramNode,
  toNode: DiagramNode,
  overrides?: Partial<
    Pick<
      DiagramEdge,
      'lineStyle' | 'arrow' | 'strokeTone' | 'label' | 'fromSide' | 'toSide'
    >
  >,
): DiagramEdge {
  const sides = pickDiagramAnchorSides(fromNode, toNode)
  const defaults = getDefaultDiagramEdgeAppearance()

  return {
    id: createId(),
    fromNodeId: fromNode.id,
    toNodeId: toNode.id,
    fromSide: overrides?.fromSide ?? sides.fromSide,
    toSide: overrides?.toSide ?? sides.toSide,
    lineStyle: overrides?.lineStyle ?? defaults.lineStyle,
    arrow: overrides?.arrow ?? defaults.arrow,
    strokeTone: overrides?.strokeTone ?? defaults.strokeTone,
    label: overrides?.label,
  }
}
