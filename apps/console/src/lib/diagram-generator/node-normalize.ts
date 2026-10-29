import {
  DEFAULT_DIAGRAM_ICON,
} from '@/lib/diagram-generator/constants'
import { createDefaultDiagramTable, normalizeDiagramTableNode } from '@/lib/diagram-generator/diagram-table'
import {
  clampDiagramScreenshotFocus,
  DEFAULT_DIAGRAM_SCREENSHOT_FOCUS,
} from '@/lib/diagram-generator/screenshot-focus'
import type { DiagramNode } from '@/lib/diagram-generator/types'

type LegacyDiagramNode = DiagramNode & {
  serviceId?: string
  kind?: string
}

export function hasDiagramNodeIcon(node: DiagramNode): boolean {
  return Boolean(node.iconSrc?.trim())
}

export function getDiagramNodeIconSrc(node: DiagramNode): string {
  if (node.kind === 'icon') {
    return node.iconSrc || DEFAULT_DIAGRAM_ICON
  }

  if (node.kind === 'service') {
    return node.iconSrc?.trim() ?? ''
  }

  return node.iconSrc ?? ''
}

export function normalizeDiagramNode(node: DiagramNode): DiagramNode {
  const legacy = node as LegacyDiagramNode
  const legacyServiceId = legacy.serviceId
  const rawKind = (legacy as { kind?: string }).kind ?? node.kind
  const kind: DiagramNode['kind'] =
    rawKind === 'component' ? 'service' : node.kind

  switch (kind) {
    case 'service': {
      const { serviceId: _legacy, ...rest } = legacy
      return {
        ...rest,
        kind: 'service',
        iconSrc: rest.iconSrc?.trim() || undefined,
        subtitle: rest.subtitle?.trim() || undefined,
      }
    }
    case 'icon':
      return {
        ...node,
        kind: 'icon',
        iconSrc: node.iconSrc || DEFAULT_DIAGRAM_ICON,
      }
    case 'screenshot':
      return {
        ...node,
        kind: 'screenshot',
        imageSrc: typeof node.imageSrc === 'string' ? node.imageSrc : undefined,
        focusX: clampDiagramScreenshotFocus(
          node.focusX ?? DEFAULT_DIAGRAM_SCREENSHOT_FOCUS.focusX,
          'x',
        ),
        focusY: clampDiagramScreenshotFocus(
          node.focusY ?? DEFAULT_DIAGRAM_SCREENSHOT_FOCUS.focusY,
          'y',
        ),
      }
    case 'table': {
      const table = normalizeDiagramTableNode({ ...node, kind: 'table' })
      return {
        ...node,
        kind: 'table',
        ...table,
      }
    }
    case 'title':
      return {
        ...node,
        kind: 'title',
        subtitle: node.subtitle?.trim() || undefined,
      }
    default:
      if (legacyServiceId) {
        const { serviceId: _legacy, ...rest } = legacy
        return { ...rest, kind } as DiagramNode
      }
      return { ...node, kind } as DiagramNode
  }
}

export function getDiagramNodeCreateDefaults(
  kind: DiagramNode['kind'],
): Partial<DiagramNode> {
  switch (kind) {
    case 'service':
      return {
        label: 'Element',
      }
    case 'icon':
      return {
        iconSrc: DEFAULT_DIAGRAM_ICON,
        label: 'Icon',
      }
    case 'screenshot':
      return {
        label: 'Screenshot',
        focusX: DEFAULT_DIAGRAM_SCREENSHOT_FOCUS.focusX,
        focusY: DEFAULT_DIAGRAM_SCREENSHOT_FOCUS.focusY,
      }
    case 'table':
      return {
        label: 'Table',
        ...createDefaultDiagramTable(),
      }
    case 'title':
      return {
        label: 'Diagram title',
      }
    default:
      return {}
  }
}
