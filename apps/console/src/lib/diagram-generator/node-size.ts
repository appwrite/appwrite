import type { DiagramNodeKind } from '@/lib/diagram-generator/types'
import { snapDiagramValue } from '@/lib/diagram-generator/templates'

export const DIAGRAM_NODE_SIZE_LIMITS: Record<
  DiagramNodeKind,
  { minWidth: number; minHeight: number; maxWidth: number; maxHeight: number }
> = {
  service: { minWidth: 160, minHeight: 56, maxWidth: 320, maxHeight: 120 },
  title: { minWidth: 240, minHeight: 48, maxWidth: 960, maxHeight: 160 },
  label: { minWidth: 80, minHeight: 28, maxWidth: 400, maxHeight: 64 },
  group: { minWidth: 200, minHeight: 120, maxWidth: 960, maxHeight: 640 },
  icon: { minWidth: 48, minHeight: 48, maxWidth: 160, maxHeight: 160 },
  screenshot: { minWidth: 160, minHeight: 100, maxWidth: 720, maxHeight: 540 },
  table: { minWidth: 176, minHeight: 96, maxWidth: 720, maxHeight: 420 },
}

export function clampDiagramNodeSize(
  kind: DiagramNodeKind,
  width: number,
  height: number,
): { width: number; height: number } {
  const limits = DIAGRAM_NODE_SIZE_LIMITS[kind]

  return {
    width: snapDiagramValue(
      Math.min(limits.maxWidth, Math.max(limits.minWidth, width)),
    ),
    height: snapDiagramValue(
      Math.min(limits.maxHeight, Math.max(limits.minHeight, height)),
    ),
  }
}
