import type { CoverBrandTheme } from '@/lib/cover-generator/brand-theme'
import type {
  DiagramEdge,
  DiagramEdgeArrow,
  DiagramEdgeLineStyle,
  DiagramEdgeStrokeTone,
} from '@/lib/diagram-generator/types'

export type DiagramEdgeAppearance = Pick<
  DiagramEdge,
  'lineStyle' | 'arrow' | 'strokeTone'
>

export type DiagramEdgePreset = DiagramEdgeAppearance & {
  id: string
  label: string
  description: string
  labelSuggestion?: string
}

export const DIAGRAM_EDGE_LINE_STYLE_LABELS: Record<DiagramEdgeLineStyle, string> = {
  solid: 'Solid',
  dashed: 'Dashed',
  dotted: 'Dotted',
}

export const DIAGRAM_EDGE_ARROW_LABELS: Record<DiagramEdgeArrow, string> = {
  forward: 'Forward arrow',
  both: 'Both ends',
  none: 'No arrow',
}

export const DIAGRAM_EDGE_STROKE_TONE_LABELS: Record<DiagramEdgeStrokeTone, string> = {
  default: 'Default',
  muted: 'Muted',
  accent: 'Accent',
  purple: 'Purple',
}

export const DIAGRAM_EDGE_LABEL_SUGGESTIONS = [
  'HTTPS',
  'REST',
  'gRPC',
  'WebSocket',
  'JSON',
  'SQL',
  'Webhook',
  'Subscribe',
  'Publish',
  'Invoke',
  'Upload',
  'Download',
] as const

export const DIAGRAM_EDGE_PRESETS: DiagramEdgePreset[] = [
  {
    id: 'request',
    label: 'Request',
    description: 'Solid line with a forward arrow',
    lineStyle: 'solid',
    arrow: 'forward',
    strokeTone: 'default',
    labelSuggestion: 'HTTPS',
  },
  {
    id: 'response',
    label: 'Response',
    description: 'Dashed return path',
    lineStyle: 'dashed',
    arrow: 'forward',
    strokeTone: 'muted',
    labelSuggestion: 'JSON',
  },
  {
    id: 'event',
    label: 'Event',
    description: 'Async or webhook delivery',
    lineStyle: 'dashed',
    arrow: 'forward',
    strokeTone: 'muted',
    labelSuggestion: 'Webhook',
  },
  {
    id: 'data',
    label: 'Data',
    description: 'Database or storage access',
    lineStyle: 'dotted',
    arrow: 'forward',
    strokeTone: 'purple',
    labelSuggestion: 'SQL',
  },
  {
    id: 'stream',
    label: 'Stream',
    description: 'Bidirectional realtime flow',
    lineStyle: 'solid',
    arrow: 'both',
    strokeTone: 'accent',
    labelSuggestion: 'WebSocket',
  },
  {
    id: 'link',
    label: 'Link',
    description: 'Lightweight association',
    lineStyle: 'dotted',
    arrow: 'none',
    strokeTone: 'muted',
  },
]

const DEFAULT_EDGE_APPEARANCE: DiagramEdgeAppearance = {
  lineStyle: 'solid',
  arrow: 'forward',
  strokeTone: 'default',
}

type LegacyDiagramEdgeKind = 'flow' | 'async' | 'data'

function isLegacyEdgeKind(value: unknown): value is LegacyDiagramEdgeKind {
  return value === 'flow' || value === 'async' || value === 'data'
}

export function migrateLegacyDiagramEdgeKind(
  kind: LegacyDiagramEdgeKind,
): DiagramEdgeAppearance {
  switch (kind) {
    case 'async':
      return { lineStyle: 'dashed', arrow: 'forward', strokeTone: 'muted' }
    case 'data':
      return { lineStyle: 'dotted', arrow: 'forward', strokeTone: 'purple' }
    case 'flow':
    default:
      return DEFAULT_EDGE_APPEARANCE
  }
}

export function normalizeDiagramEdge(edge: DiagramEdge): DiagramEdge {
  const legacyKind = (edge as DiagramEdge & { kind?: unknown }).kind
  const hasAppearance =
    typeof edge.lineStyle === 'string' &&
    typeof edge.arrow === 'string' &&
    typeof edge.strokeTone === 'string'

  const appearance = hasAppearance
    ? {
        lineStyle: edge.lineStyle,
        arrow: edge.arrow,
        strokeTone: edge.strokeTone,
      }
    : isLegacyEdgeKind(legacyKind)
      ? migrateLegacyDiagramEdgeKind(legacyKind)
      : DEFAULT_EDGE_APPEARANCE

  return {
    id: edge.id,
    fromNodeId: edge.fromNodeId,
    toNodeId: edge.toNodeId,
    fromSide: edge.fromSide,
    toSide: edge.toSide,
    label: edge.label,
    ...appearance,
  }
}

export function getDefaultDiagramEdgeAppearance(): DiagramEdgeAppearance {
  return { ...DEFAULT_EDGE_APPEARANCE }
}

export function getDiagramEdgeStroke(
  tone: DiagramEdgeStrokeTone,
  brand: CoverBrandTheme,
  selected: boolean,
): string {
  if (selected) return brand.brandCta

  switch (tone) {
    case 'muted':
      return brand.mutedForeground
    case 'accent':
      return brand.brandCta
    case 'purple':
      return brand.brandPurple
    case 'default':
    default:
      return brand.foreground
  }
}

export function getDiagramEdgeDash(
  lineStyle: DiagramEdgeLineStyle,
): string | undefined {
  switch (lineStyle) {
    case 'dashed':
      return '7 6'
    case 'dotted':
      return '2 5'
    case 'solid':
    default:
      return undefined
  }
}

export function getDiagramEdgeMarkerSuffix(
  tone: DiagramEdgeStrokeTone,
  selected: boolean,
): string {
  if (selected) return 'selected'
  return tone
}

export function edgeUsesForwardArrow(arrow: DiagramEdgeArrow): boolean {
  return arrow === 'forward' || arrow === 'both'
}

export function edgeUsesBackwardArrow(arrow: DiagramEdgeArrow): boolean {
  return arrow === 'both'
}

export function getDiagramEdgeOpacity(
  lineStyle: DiagramEdgeLineStyle,
  tone: DiagramEdgeStrokeTone,
  options?: { selected?: boolean; part?: 'stroke' | 'label' },
): number {
  const selected = options?.selected ?? false
  const part = options?.part ?? 'stroke'

  if (part === 'label') {
    return selected ? 1 : 0.94
  }

  if (selected) {
    return tone === 'muted' ? 0.48 : 0.56
  }

  if (tone === 'muted') {
    return lineStyle === 'dashed' ? 0.26 : 0.3
  }

  if (tone === 'accent' || tone === 'purple') {
    return 0.34
  }

  return 0.32
}
